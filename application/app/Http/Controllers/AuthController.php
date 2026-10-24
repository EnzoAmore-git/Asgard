<?php

namespace App\Http\Controllers;

use App\Models\Identity;
use App\Services\Auth\AuthFlowService;
use App\Services\Auth\IdentityResolver;
use App\Services\Auth\ProviderClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function start(Request $request, string $provider, AuthFlowService $flows)
    {
        return redirect()->away($flows->start($request, $provider, false));
    }

    public function link(Request $request, string $provider, AuthFlowService $flows)
    {
        return response()->json(['url' => $flows->start($request, $provider, true)]);
    }

    public function callback(Request $request, string $provider, AuthFlowService $flows, ProviderClient $client, IdentityResolver $resolver)
    {
        $data = $request->validate(['state' => 'required|string|max:128', 'code' => 'nullable|string|max:2048', 'device_id' => 'nullable|string|max:512']);
        $flow = $flows->consume($request, $provider, $data['state']);
        if ($request->has('error') || empty($data['code'])) {
            return redirect('/')->with('auth_error', 'Вход отменён. Можно попробовать ещё раз.');
        }
        try {
            $identity = $client->identity($flow, $data['code'], $data['state'], $data['device_id'] ?? null);
        } catch (\Throwable $error) {
            Log::notice('Provider sign-in failed', ['provider' => $provider, 'type' => get_class($error)]);

            return redirect('/')->with('auth_error', 'Не удалось подтвердить вход. Попробуйте ещё раз.');
        }
        $user = $resolver->resolve($provider, $identity, $flow->purpose === 'link' ? $request->user() : null);
        Auth::login($user);
        $request->session()->regenerate(true);
        $invite = $request->session()->get('pending_invitation');

        return redirect(is_string($invite) && preg_match('/^[A-Za-z0-9]{64}$/', $invite) ? '/invite/'.$invite : '/');
    }

    public function me(Request $request, ProviderClient $providers)
    {
        $user = $request->user();

        return response()->json([
            'user' => $user ? ['id' => $user->id, 'name' => $user->name] : null,
            'identities' => $user ? Identity::where('user_id', $user->id)->pluck('provider') : [],
            'providers' => ['telegram' => $providers->configured('telegram'), 'vk' => $providers->configured('vk')],
            'csrf_token' => csrf_token(), 'error' => $request->session()->pull('auth_error'),
            'local_access' => (bool) $request->session()->get('local_access', false) && app()->environment('local'),
        ]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function revokeOtherSessions(Request $request)
    {
        DB::table('sessions')->where('user_id', $request->user()->id)
            ->where('id', '!=', hash('sha256', $request->session()->getId()))->delete();

        return response()->noContent();
    }
}
