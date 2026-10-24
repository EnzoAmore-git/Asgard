<?php

namespace App\Services\Auth;

use App\Models\AuthFlow;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ProviderClient
{
    public function configured(string $provider): bool
    {
        if (! in_array($provider, ['telegram', 'vk'], true)) {
            return false;
        }
        $settings = config('asgard.providers.'.$provider);
        if (empty($settings['client_id']) || empty($settings['redirect_uri'])) {
            return false;
        }
        $uri = parse_url($settings['redirect_uri']);
        if (! $uri || ! in_array($uri['scheme'] ?? '', ['https', 'http'], true) || empty($uri['host'])) {
            return false;
        }
        if (($uri['scheme'] ?? '') !== 'https' && ! in_array($uri['host'], ['localhost', '127.0.0.1'], true)) {
            return false;
        }

        return $provider !== 'telegram' || ! empty($settings['client_secret']);
    }

    public function authorizationUrl(string $provider, string $state, string $verifier, string $nonce): string
    {
        abort_unless($this->configured($provider), 503, 'Этот способ входа пока не подключен.');
        $settings = config('asgard.providers.'.$provider);
        $query = [
            'client_id' => $settings['client_id'], 'redirect_uri' => $settings['redirect_uri'],
            'response_type' => 'code', 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => $provider === 'vk' ? 's256' : 'S256',
        ];
        if ($provider === 'telegram') {
            $query += ['scope' => 'openid profile', 'nonce' => $nonce];
        }
        $endpoint = $provider === 'telegram' ? 'https://oauth.telegram.org/auth' : 'https://id.vk.ru/authorize';

        return $endpoint.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public function identity(AuthFlow $flow, string $code, string $state, ?string $device): array
    {
        $settings = config('asgard.providers.'.$flow->provider);
        $payload = [
            'grant_type' => 'authorization_code', 'client_id' => $settings['client_id'],
            'redirect_uri' => $settings['redirect_uri'], 'code' => $code, 'code_verifier' => $flow->verifier,
        ];
        if ($flow->provider === 'telegram') {
            $tokens = Http::asForm()->withBasicAuth((string) $settings['client_id'], $settings['client_secret'])
                ->timeout(10)->post('https://oauth.telegram.org/token', $payload)->throw()->json();

            return $this->telegramIdentity((string) ($tokens['id_token'] ?? ''), $flow);
        }
        if (! $device || strlen($device) > 512) {
            throw new RuntimeException('Missing device binding.');
        }
        $tokens = Http::asForm()->timeout(10)->post('https://id.vk.ru/oauth2/auth', $payload + [
            'state' => $state, 'device_id' => $device,
        ])->throw()->json();
        if (! is_array($tokens) || isset($tokens['error']) || ! is_string($tokens['state'] ?? null)
            || ! hash_equals($state, $tokens['state']) || ! is_string($tokens['access_token'] ?? null)) {
            throw new RuntimeException('Invalid VK token response.');
        }
        $profile = Http::asForm()->timeout(10)->post('https://id.vk.ru/oauth2/user_info', [
            'client_id' => $settings['client_id'], 'access_token' => $tokens['access_token'],
        ])->throw()->json();
        $user = $profile['user'] ?? [];
        $subject = (string) ($user['user_id'] ?? '');
        if (! ctype_digit($subject) || $subject === '0' || strlen($subject) > 32
            || $subject !== (string) ($tokens['user_id'] ?? '')) {
            throw new RuntimeException('Invalid VK identity.');
        }

        return ['subject' => $subject, 'name' => $this->displayName(trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')))];
    }

    private function telegramIdentity(string $token, AuthFlow $flow): array
    {
        if ($token === '' || strlen($token) > 16384) {
            throw new RuntimeException('Invalid ID token.');
        }
        $keys = Cache::remember('telegram.oidc.jwks', 3600, fn () => Http::timeout(10)->get('https://oauth.telegram.org/.well-known/jwks.json')->throw()->json());
        $allowed = array_values(array_filter($keys['keys'] ?? [], fn ($key) => ($key['kty'] ?? '') === 'RSA' && ($key['alg'] ?? 'RS256') === 'RS256' && ($key['use'] ?? 'sig') === 'sig'));
        $claims = (array) JWT::decode($token, JWK::parseKeySet(['keys' => $allowed], 'RS256'));
        $audience = $claims['aud'] ?? null;
        $client = (string) config('asgard.providers.telegram.client_id');
        $audiences = is_array($audience) ? $audience : [$audience];
        $subject = (string) ($claims['sub'] ?? '');
        if (($claims['iss'] ?? null) !== 'https://oauth.telegram.org'
            || ! in_array($client, $audiences, true)
            || (count($audiences) > 1 && ($claims['azp'] ?? null) !== $client)
            || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= time()
            || ! is_int($claims['iat'] ?? null) || $claims['iat'] > time()
            || ! is_string($claims['nonce'] ?? null)
            || ! hash_equals($flow->nonce_hash, hash('sha256', $claims['nonce']))
            || ! ctype_digit($subject) || $subject === '0' || strlen($subject) > 32) {
            throw new RuntimeException('Invalid Telegram claims.');
        }

        return ['subject' => $subject, 'name' => $this->displayName($claims['name'] ?? '')];
    }

    private function displayName(mixed $name): string
    {
        return is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 80) : 'Участник';
    }
}
