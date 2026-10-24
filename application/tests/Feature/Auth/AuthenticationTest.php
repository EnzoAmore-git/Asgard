<?php

namespace Tests\Feature\Auth;

use App\Models\AuthFlow;
use App\Models\Identity;
use App\Models\User;
use App\Services\Auth\IdentityResolver;
use App\Session\HashedDatabaseSessionHandler;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private static $privateKey;

    private static array $jwk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        config(['asgard.providers.telegram' => ['client_id' => '12345', 'client_secret' => 'test-secret', 'redirect_uri' => 'https://asgard.example/auth/telegram/callback'],
            'asgard.providers.vk' => ['client_id' => '54321', 'redirect_uri' => 'https://asgard.example/auth/vk/callback']]);
        if (! self::$privateKey) {
            self::$privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $details = openssl_pkey_get_details(self::$privateKey);
            self::$jwk = ['kty' => 'RSA', 'kid' => 'test', 'alg' => 'RS256', 'use' => 'sig',
                'n' => JWT::urlsafeB64Encode($details['rsa']['n']), 'e' => JWT::urlsafeB64Encode($details['rsa']['e'])];
        }
    }

    private function start(string $provider = 'telegram', bool $link = false): array
    {
        $response = $link ? $this->postJson('/api/account/link/'.$provider) : $this->get('/auth/'.$provider.'/start');
        $url = $link ? $response->assertOk()->json('url') : $response->assertRedirect()->headers->get('Location');
        $this->withCookie(config('session.cookie'), $response->getCookie(config('session.cookie'))->getValue());
        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);

        return $parameters;
    }

    private function telegram(array $parameters, array $overrides = [], string $algorithm = 'RS256'): void
    {
        $claims = array_replace(['iss' => 'https://oauth.telegram.org', 'aud' => '12345', 'sub' => '777',
            'iat' => time() - 5, 'exp' => time() + 300, 'nonce' => $parameters['nonce'], 'name' => 'Рагнар'], $overrides);
        $token = JWT::encode($claims, $algorithm === 'RS256' ? self::$privateKey : 'attacker-test-key-which-is-long-enough-123456', $algorithm, 'test');
        Http::fake([
            'https://oauth.telegram.org/token' => Http::response(['id_token' => $token]),
            'https://oauth.telegram.org/.well-known/jwks.json' => Http::response(['keys' => [self::$jwk]]),
        ]);
    }

    public function test_login_uses_pkce_and_one_time_session_bound_state(): void
    {
        $parameters = $this->start();
        $flow = AuthFlow::firstOrFail();
        $this->assertSame('S256', $parameters['code_challenge_method']);
        $this->assertSame(JWT::urlsafeB64Encode(hash('sha256', $flow->verifier, true)), $parameters['code_challenge']);
        $this->assertNotSame($parameters['state'], $flow->state_hash);
        $this->assertNotSame($flow->verifier, DB::table('auth_flows')->value('verifier'));
        $this->telegram($parameters);
        $this->get('/auth/telegram/callback?'.http_build_query(['state' => $parameters['state'], 'code' => 'ok']))->assertRedirect('/');
        $this->assertAuthenticated();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('identities', 1);
        $this->getJson('/auth/telegram/callback?'.http_build_query(['state' => $parameters['state'], 'code' => 'ok']))->assertForbidden();
        Http::assertSentCount(2);
    }

    public function test_bad_state_never_calls_provider(): void
    {
        $this->getJson('/auth/telegram/callback?state=wrong&code=code')->assertNotFound();
        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_expired_state_and_wrong_provider_are_rejected(): void
    {
        $parameters = $this->start();
        $this->getJson('/auth/vk/callback?'.http_build_query(['state' => $parameters['state'], 'code' => 'ok']))->assertForbidden();
        $this->travel(11)->minutes();
        $this->getJson('/auth/telegram/callback?'.http_build_query(['state' => $parameters['state'], 'code' => 'ok']))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_state_from_different_session_cannot_be_consumed(): void
    {
        $parameters = $this->start();
        $this->withCookie(config('session.cookie'), str_repeat('b', 40));
        $this->getJson('/auth/telegram/callback?'.http_build_query(['state' => $parameters['state'], 'code' => 'ok']))->assertForbidden();
        $this->assertNull(AuthFlow::first()->used_at);
        Http::assertNothingSent();
    }

    public function test_valid_second_provider_links_to_existing_user_without_creating_an_account(): void
    {
        $user = User::factory()->create();
        Identity::create(['user_id' => $user->id, 'provider' => 'telegram', 'subject' => '777']);
        $this->actingAs($user);
        $parameters = $this->start('vk', true);
        Http::fake([
            'https://id.vk.ru/oauth2/auth' => Http::response(['state' => $parameters['state'], 'access_token' => 'vk-test-token', 'user_id' => 555]),
            'https://id.vk.ru/oauth2/user_info' => Http::response(['user' => ['user_id' => '555', 'first_name' => 'Рагнар']]),
        ]);
        $this->get('/auth/vk/callback?'.http_build_query(['state' => $parameters['state'], 'code' => 'ok', 'device_id' => 'device']))->assertRedirect('/');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('identities', 2);
        $this->assertDatabaseHas('identities', ['user_id' => $user->id, 'provider' => 'vk', 'subject' => '555']);
        $this->assertSame($user->id, app(IdentityResolver::class)->resolve('vk', ['subject' => '555', 'name' => 'Рагнар'], null)->id);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/oauth2/auth') && $request['device_id'] === 'device' && strlen($request['code_verifier']) >= 43);
    }

    public function test_taken_identity_cannot_be_moved_to_another_user(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        Identity::create(['user_id' => $owner->id, 'provider' => 'telegram', 'subject' => '777']);
        $this->actingAs($attacker);
        $parameters = $this->start('telegram', true);
        $this->telegram($parameters);
        $response = $this->getJson('/auth/telegram/callback?'.http_build_query(['state' => $parameters['state'], 'code' => 'ok']));
        $this->assertSame(409, $response->status(), $response->json('message') ?? 'No message');
        $this->assertDatabaseHas('identities', ['user_id' => $owner->id, 'provider' => 'telegram', 'subject' => '777']);
        $this->assertAuthenticatedAs($attacker);
    }

    public function test_matching_names_and_emails_are_not_identity_proof(): void
    {
        $user = User::factory()->create(['name' => 'Рагнар']);
        $other = app(IdentityResolver::class)->resolve('telegram', ['subject' => '777', 'name' => 'Рагнар'], null);
        $this->assertNotSame($user->id, $other->id);
    }

    public function test_invalid_signature_audience_issuer_nonce_and_expiry_are_rejected(): void
    {
        foreach ([['aud' => 'wrong'], ['iss' => 'https://attacker.example'], ['nonce' => 'wrong'], ['exp' => time() - 300]] as $overrides) {
            Cache::flush();
            $parameters = $this->start();
            $this->telegram($parameters, $overrides);
            $this->get('/auth/telegram/callback?'.http_build_query(['state' => $parameters['state'], 'code' => 'ok']))->assertRedirect('/');
            $this->assertGuest();
        }
        Cache::flush();
        $parameters = $this->start();
        $this->telegram($parameters, [], 'HS256');
        $this->get('/auth/telegram/callback?'.http_build_query(['state' => $parameters['state'], 'code' => 'ok']))->assertRedirect('/');
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_missing_provider_config_disables_login_without_network(): void
    {
        config(['asgard.providers.telegram.client_secret' => null]);
        $this->getJson('/api/me')->assertJsonPath('providers.telegram', false)->assertJsonMissingPath('client_secret');
        $this->getJson('/auth/telegram/start')->assertServiceUnavailable();
        Http::assertNothingSent();
    }

    public function test_vk_response_must_match_state_and_token_user(): void
    {
        $parameters = $this->start('vk');
        Http::fake(['https://id.vk.ru/oauth2/auth' => Http::response(['state' => $parameters['state'], 'access_token' => 'test', 'user_id' => '555']),
            'https://id.vk.ru/oauth2/user_info' => Http::response(['user' => ['user_id' => '666']])]);
        $this->get('/auth/vk/callback?'.http_build_query(['state' => $parameters['state'], 'code' => 'ok', 'device_id' => 'device']))->assertRedirect('/');
        $this->assertGuest();
        $this->assertDatabaseCount('identities', 0);
    }

    public function test_logout_and_security_headers(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/me')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->postJson('/api/logout')->assertNoContent();
        $this->assertGuest();
    }

    public function test_revoking_other_sessions_preserves_current_and_other_users_sessions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->withSession(['_token' => 'session-test']);
        $this->getJson('/api/me')->assertOk();
        $current = hash('sha256', session()->getId());
        $this->withCookie(config('session.cookie'), session()->getId());
        foreach ([[$current, $user->id], [str_repeat('a', 64), $user->id], [str_repeat('b', 64), $other->id]] as [$id, $userId]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
        }
        $this->postJson('/api/account/revoke-other-sessions')->assertNoContent();
        $this->assertDatabaseHas('sessions', ['id' => $current]);
        $this->assertDatabaseMissing('sessions', ['id' => str_repeat('a', 64)]);
        $this->assertDatabaseHas('sessions', ['id' => str_repeat('b', 64)]);
    }

    public function test_sessions_store_only_hashes_of_browser_identifiers(): void
    {
        $handler = new HashedDatabaseSessionHandler(DB::connection(), 'sessions', 120, $this->app);
        $raw = str_repeat('a', 40);
        $handler->write($raw, 'session payload');
        $this->assertDatabaseHas('sessions', ['id' => hash('sha256', $raw)]);
        $this->assertDatabaseMissing('sessions', ['id' => $raw]);
        $this->assertSame('session payload', $handler->read($raw));
        $handler->write($raw, 'updated payload');
        $this->assertSame('updated payload', $handler->read($raw));
        $handler->destroy($raw);
        $this->assertDatabaseCount('sessions', 0);
    }

    public function test_mutating_request_requires_csrf_when_test_bypass_is_disabled(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->actingAs(User::factory()->create())->withSession(['_token' => 'known-csrf-token']);
        $this->postJson('/api/logout')->assertStatus(419);
        $this->assertAuthenticated();
        $this->withHeader('X-CSRF-TOKEN','known-csrf-token')->postJson('/api/logout')->assertNoContent();
        $this->assertGuest();
    }
}
