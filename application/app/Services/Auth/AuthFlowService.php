<?php

namespace App\Services\Auth;

use App\Models\AuthFlow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuthFlowService
{
    public function __construct(private ProviderClient $providers) {}

    public function start(Request $request, string $provider, bool $link): string
    {
        abort_unless($this->providers->configured($provider), 503, 'Этот способ входа пока не подключен.');
        abort_if($link && ! $request->user(), 401);
        abort_if(! $link && $request->user(), 409, 'Для привязки используйте настройки аккаунта.');
        $state = Str::random(64);
        $verifier = Str::random(96);
        $nonce = Str::random(64);
        AuthFlow::create([
            'state_hash' => hash('sha256', $state), 'session_hash' => hash('sha256', $request->session()->getId()),
            'provider' => $provider, 'purpose' => $link ? 'link' : 'login',
            'user_id' => $link ? $request->user()->id : null,
            'verifier' => $verifier, 'nonce_hash' => hash('sha256', $nonce), 'expires_at' => now()->addMinutes(10),
        ]);

        return $this->providers->authorizationUrl($provider, $state, $verifier, $nonce);
    }

    public function consume(Request $request, string $provider, string $state): AuthFlow
    {
        return DB::transaction(function () use ($request, $provider, $state) {
            $flow = AuthFlow::where('state_hash', hash('sha256', $state))->lockForUpdate()->firstOrFail();
            abort_unless($flow->provider === $provider && hash_equals($flow->session_hash, hash('sha256', $request->session()->getId())), 403, 'Вход начат в другом сеансе. Начните его заново в этом браузере.');
            abort_if($flow->used_at || $flow->expires_at->lte(now()), 403, 'Попробуйте начать вход заново.');
            abort_if($flow->purpose === 'link' && $request->user()?->id !== $flow->user_id, 403, 'Для привязки вернитесь в аккаунт, из которого начали вход.');
            abort_if($flow->purpose === 'login' && $request->user(), 409);
            $flow->used_at = now();
            $flow->save();

            return $flow;
        }, 3);
    }
}
