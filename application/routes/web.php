<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClubController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\LocalAccessController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\TrainingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::pattern('club', '[0-9]+');
Route::pattern('membership', '[0-9]+');
Route::pattern('invitation', '[0-9]+');
Route::pattern('training', '[0-9]+');
Route::pattern('template', '[0-9]+');

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
        Route::prefix('clubs/{club}')->controller(TrainingController::class)->group(function () {
            Route::get('trainings', 'index');
            Route::get('training-options', 'options');
            Route::get('trainings/{training}', 'show');
            Route::post('trainings', 'create')->middleware('throttle:30,1');
            Route::put('trainings/{training}', 'update');
            Route::post('trainings/{training}/cancel', 'cancel');
            Route::put('trainings/{training}/rsvp', 'rsvp');
            Route::put('trainings/{training}/attendance', 'draft');
            Route::post('trainings/{training}/close', 'close')->middleware('throttle:30,1');
            Route::post('training-templates', 'template');
            Route::put('training-templates/{template}', 'updateTemplate');
            Route::post('training-templates/{template}/generate', 'generate')->middleware('throttle:10,1');
            Route::get('billing', 'billing');
            Route::post('billing/tariff', 'configure');
            Route::post('billing/exemptions/{membership}', 'exemption');
        });
        Route::prefix('clubs/{club}/finance')->controller(PaymentController::class)->group(function () {
            Route::get('payment-options', 'options');
            Route::get('claims', 'claims'); Route::post('claims/{claim}/cheque','uploadCheque')->whereNumber('claim')->middleware('throttle:10,1'); Route::get('claims/{claim}/cheque','downloadCheque')->whereNumber('claim');
            Route::post('claims', 'claim')->middleware('throttle:30,1');
            Route::get('receipts', 'receipts');
            Route::post('receipts', 'receipt')->middleware('throttle:30,1');
            Route::post('claims/{claim}/review', 'review')->whereNumber('claim');
            Route::post('receipts/{receipt}/identify', 'identify')->whereNumber('receipt');
            Route::post('receipts/{receipt}/refund', 'refund')->whereNumber('receipt'); Route::post('receipts/{receipt}/bank-cancel','bankCancel')->whereNumber('receipt');
            Route::post('transfers', 'transfer'); Route::post('opening-refunds','refundOpening');
            Route::get('expenses', 'expenses');
            Route::post('expenses', 'expense');
            Route::post('expenses/{occurrence}/pay', 'payExpense')->whereNumber('occurrence');
            Route::post('expense-templates', 'template');
            Route::put('expense-templates/{template}', 'updateTemplate');
            Route::post('expense-templates/{template}/generate', 'generate');
            Route::get('reconciliations', 'reconciliations');
            Route::post('reconciliations', 'reconcile');
            Route::post('reconciliations/{reconciliation}/adjust', 'adjustment')->whereNumber('reconciliation');
        });
        Route::prefix('clubs/{club}/finance')->group(function () {
            Route::get('me', [FinanceController::class, 'own']);
            Route::get('summary', [FinanceController::class, 'summary']);
            Route::get('members', [FinanceController::class, 'members']);
            Route::get('journal', [FinanceController::class, 'journal']);
            Route::post('opening', [FinanceController::class, 'opening'])->middleware('throttle:30,1');
        });
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
