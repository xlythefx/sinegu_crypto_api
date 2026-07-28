<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AdminInvoiceController;
use App\Http\Controllers\ExchangeAccountController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\AdminReferralController;
use App\Http\Controllers\PayoutMethodController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\SandboxController;
use App\Http\Controllers\StrategyController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    Route::get('/dashboard/daily-pnl', [DashboardController::class, 'dailyPnl']);
    Route::get('/dashboard/asset-performance', [DashboardController::class, 'assetPerformance']);
    Route::get('/analytics', [AnalyticsController::class, 'index']);
    Route::get('/binance/positions', [DashboardController::class, 'openPositions']);
    Route::get('/binance/past-positions', [DashboardController::class, 'pastPositions']);

    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{id}', [InvoiceController::class, 'show']);

    Route::prefix('user')->group(function () {
        Route::put('/profile', [ProfileController::class, 'updateProfile']);
        Route::post('/image', [ProfileController::class, 'uploadImage']);
        Route::put('/password', [ProfileController::class, 'updatePassword']);
    });

    Route::prefix('referrals')->group(function () {
        Route::get('/', [ReferralController::class, 'index']);
        Route::post('/code', [ReferralController::class, 'createCode']);
        Route::get('/community', [ReferralController::class, 'community']);
        Route::post('/community', [ReferralController::class, 'saveCommunity']);
        Route::post('/members/remove', [ReferralController::class, 'removeMember']);
        Route::get('/payouts', [ReferralController::class, 'payouts']);
    });

    Route::prefix('payout-methods')->group(function () {
        Route::get('/', [PayoutMethodController::class, 'index']);
        Route::post('/wallets', [PayoutMethodController::class, 'storeWallet']);
        Route::put('/wallets/{id}', [PayoutMethodController::class, 'updateWallet']);
        Route::delete('/wallets/{id}', [PayoutMethodController::class, 'destroyWallet']);
        Route::post('/banks', [PayoutMethodController::class, 'storeBank']);
        Route::put('/banks/{id}', [PayoutMethodController::class, 'updateBank']);
        Route::delete('/banks/{id}', [PayoutMethodController::class, 'destroyBank']);
        Route::post('/set-main', [PayoutMethodController::class, 'setMain']);
    });

    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('/master-stats', [AdminController::class, 'masterStats']);
        Route::get('/daily-pnl', [AdminController::class, 'dailyPnl']);
        Route::get('/positions', [AdminController::class, 'positions']);
        Route::delete('/positions/{id}', [AdminController::class, 'deletePosition']);
        Route::delete('/past-positions/{id}', [AdminController::class, 'deletePastPosition']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::post('/users/{uniId}/accept', [AdminController::class, 'acceptUser']);
        Route::post('/users/{uniId}/reject', [AdminController::class, 'rejectUser']);

        Route::get('/strategies', [StrategyController::class, 'index']);
        Route::put('/strategies/{key}', [StrategyController::class, 'setEnabled']);

        Route::get('/assets', [AssetController::class, 'index']);
        Route::post('/assets', [AssetController::class, 'store']);
        Route::put('/assets/{asset}', [AssetController::class, 'update']);
        Route::delete('/assets/{asset}', [AssetController::class, 'destroy']);

        Route::get('/invoices', [AdminInvoiceController::class, 'index']);
        Route::get('/invoices/{id}', [AdminInvoiceController::class, 'show']);
        Route::post('/invoices/generate', [AdminInvoiceController::class, 'generate']);
        Route::put('/invoices/{id}', [AdminInvoiceController::class, 'update']);
        Route::delete('/invoices/{id}', [AdminInvoiceController::class, 'destroy']);

        Route::prefix('affiliate')->group(function () {
            Route::get('/overview', [AdminReferralController::class, 'overview']);
            Route::get('/ledger', [AdminReferralController::class, 'ledger']);
            Route::get('/ledger-stats', [AdminReferralController::class, 'ledgerStats']);
            Route::get('/referrers/{uniId}/payouts', [AdminReferralController::class, 'referrerPayouts']);
            Route::get('/referrers/{uniId}/releasable', [AdminReferralController::class, 'referrerReleasable']);
            Route::post('/release', [AdminReferralController::class, 'release']);
            Route::delete('/payouts/{id}', [AdminReferralController::class, 'destroyPayout']);
            Route::get('/payouts/{id}/proof', [AdminReferralController::class, 'downloadProof']);
            Route::get('/users/{uniId}/referrals', [AdminReferralController::class, 'userReferrals']);
        });

        Route::get('/sandbox/users', [SandboxController::class, 'listUsers']);
        Route::post('/sandbox/users', [SandboxController::class, 'createUser']);
        Route::delete('/sandbox/users/{uniId}', [SandboxController::class, 'deleteUser']);
        Route::delete('/sandbox/users/{uniId}/positions', [SandboxController::class, 'clearPositions']);
        Route::post('/sandbox/positions', [SandboxController::class, 'insertPositions']);
    });

    Route::prefix('exchange')->group(function () {
        Route::get('/accounts', [ExchangeAccountController::class, 'index']);
        Route::post('/binance', [ExchangeAccountController::class, 'storeBinance']);
        Route::delete('/accounts/{id}', [ExchangeAccountController::class, 'destroy']);
    });
});
