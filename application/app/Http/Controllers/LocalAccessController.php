<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

// Development-only tool. Never a production alternative to Telegram or VK.
class LocalAccessController extends Controller
{
    public function __invoke(Request $request, string $token)
    {
        abort_unless(app()->environment('local') && in_array($request->getHost(), ['localhost', '127.0.0.1'], true)
            && in_array($request->ip(), ['127.0.0.1', '::1'], true), 404);
        $key = 'asgard.local-access.'.hash('sha256', $token);
        $userId = Cache::lock($key.'.lock', 5)->block(2, fn () => Cache::pull($key));
        abort_unless(is_int($userId), 404);
        Auth::login(User::findOrFail($userId));
        $request->session()->regenerate(true);
        $request->session()->put('local_access', true);

        return redirect('/');
    }
}
