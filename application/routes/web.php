<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClubController;
use App\Http\Controllers\LocalAccessController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::pattern('club', '[0-9]+');
Route::pattern('membership', '[0-9]+');
Route::pattern('invitation', '[0-9]+');

Route::get('/', fn () => view('application'));
Route::get('/local-access/{token}', LocalAccessController::class)->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:10,1');
Route::get('/invite/{token}', function (Request $request, string $token) {
    $request->session()->put('pending_invitation', $token);

    return view('application');
})->where('token', '[a-zA-Z0-9]{64}');
Route::get('/auth/{provider}/start', [AuthController::class, 'start'])->whereIn('provider', ['telegram', 'vk'])->middleware('throttle:20,1');
Route::get('/auth/{provider}/callback', [AuthController::class, 'callback'])->whereIn('provider', ['telegram', 'vk'])->middleware('throttle:30,1');
Route::prefix('api')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/invitations/{token}', [ClubController::class, 'invitationInfo'])->where('token', '[a-zA-Z0-9]{64}')->middleware('throttle:30,1');
    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/account/link/{provider}', [AuthController::class, 'link'])->whereIn('provider', ['telegram', 'vk'])->middleware('throttle:10,1');
        Route::post('/account/revoke-other-sessions', [AuthController::class, 'revokeOtherSessions']);
        Route::get('/clubs', [ClubController::class, 'index']);
        Route::get('/clubs/{club}', [ClubController::class, 'show'])->whereNumber('club');
        Route::get('/clubs/{club}/members', [ClubController::class, 'members']);
        Route::get('/clubs/{club}/invitations', [ClubController::class, 'invitations']);
        Route::post('/clubs/{club}/invitations', [ClubController::class, 'invite'])->middleware('throttle:10,1');
        Route::delete('/clubs/{club}/invitations/{invitation}', [ClubController::class, 'revoke']);
        Route::post('/invitations/{token}/accept', [ClubController::class, 'accept'])->where('token', '[a-zA-Z0-9]{64}')->middleware('throttle:20,1');
        Route::put('/clubs/{club}/members/{membership}/permissions', [ClubController::class, 'permissions']);
        Route::put('/clubs/{club}/members/{membership}/status', [ClubController::class, 'status']);
        Route::post('/clubs/{club}/ownership', [ClubController::class, 'transfer']);
        Route::get('/clubs/{club}/audit', [ClubController::class, 'audit']);
    });
});
