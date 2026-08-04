<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminDatabaseController;
use App\Http\Controllers\AdminEngineController;
use App\Http\Controllers\AdminInvoiceController;
use App\Http\Controllers\AdminMaintenanceController;
use App\Http\Controllers\AdminManualTradeController;
use App\Http\Controllers\AdminReferralController;
use App\Http\Controllers\AdminTradeLogController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CoinsbuyWebhookController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EngineController;
use App\Http\Controllers\EngineSyncController;
use App\Http\Controllers\ExchangeAccountController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PayoutMethodController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicStatsController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\SandboxController;
use App\Http\Controllers\SandboxInvoiceController;
use App\Http\Controllers\StrategyController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

// Marketing site — unauthenticated. Percentages and counts only, never
// balances or amounts (see PublicStatsController's privacy rule). Server-side
// cached, so the landing page's traffic never turns into query load.
Route::prefix('public')->group(function () {
    Route::get('/track-record', [PublicStatsController::class, 'trackRecord']);
});

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
    // Read-only asset catalog for traders (enabled assets, no sizing columns).
    Route::get('/assets', [AssetController::class, 'catalog']);
    Route::get('/binance/positions', [DashboardController::class, 'openPositions']);
    Route::get('/binance/past-positions', [DashboardController::class, 'pastPositions']);

    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{id}', [InvoiceController::class, 'show']);

    // Payment initiation. Ownership is enforced in the controller
    // (Invoice::forUser) — an invoice id from the browser is never trusted on
    // its own, and the amount always comes from the row.
    Route::prefix('payments')->group(function () {
        Route::get('/methods', [PaymentController::class, 'methods']);
        Route::post('/stripe/checkout-session', [PaymentController::class, 'stripeCheckout'])
            ->middleware('throttle:12,1');
        Route::post('/coinsbuy/deposit', [PaymentController::class, 'coinsbuyDeposit'])
            ->middleware('throttle:12,1');
    });

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
        Route::get('/performance', [AdminController::class, 'performance']);
        Route::get('/positions', [AdminController::class, 'positions']);
        Route::delete('/positions/{id}', [AdminController::class, 'deletePosition']);
        Route::delete('/past-positions/{id}', [AdminController::class, 'deletePastPosition']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::post('/users/{uniId}/accept', [AdminController::class, 'acceptUser']);
        Route::post('/users/{uniId}/reject', [AdminController::class, 'rejectUser']);
        Route::get('/users/{uniId}', [AdminUserController::class, 'show']);
        Route::get('/users/{uniId}/summary', [AdminUserController::class, 'summary']);
        Route::get('/users/{uniId}/daily-pnl', [AdminUserController::class, 'dailyPnl']);
        Route::get('/users/{uniId}/positions', [AdminUserController::class, 'positions']);
        Route::get('/users/{uniId}/invoices', [AdminUserController::class, 'invoices']);
        Route::put('/users/{uniId}', [AdminUserController::class, 'update']);

        Route::get('/strategies', [StrategyController::class, 'index']);
        Route::put('/strategies/{key}', [StrategyController::class, 'setEnabled']);

        Route::get('/assets', [AssetController::class, 'index']);
        Route::post('/assets', [AssetController::class, 'store']);
        Route::put('/assets/{asset}', [AssetController::class, 'update']);
        Route::delete('/assets/{asset}', [AssetController::class, 'destroy']);

        // Engine signal log (read-only) — what the engine did per signal, incl.
        // the balance-proportional sizing decision per account.
        Route::get('/trade-logs', [AdminTradeLogController::class, 'index']);
        Route::get('/trade-logs/{id}', [AdminTradeLogController::class, 'show']);

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

        // Manual trade console (sandbox sub-page): proxies signed webhooks to
        // the trading engine so the secret never reaches the browser.
        Route::get('/manual-trade/targets', [AdminManualTradeController::class, 'targets']);
        Route::get('/manual-trade/engine', [AdminManualTradeController::class, 'engineStatus']);
        Route::post('/manual-trade/send', [AdminManualTradeController::class, 'send']);

        // Bot Engine ops page: systemd state + health, journal tail, restart.
        Route::get('/engine/status', [AdminEngineController::class, 'status']);
        Route::get('/engine/logs', [AdminEngineController::class, 'logs']);
        Route::post('/engine/restart', [AdminEngineController::class, 'restart']);

        // Server-side cache flush (Laravel + engine). Browser caching is
        // handled by nginx headers, not here.
        Route::post('/cache/clear', [AdminMaintenanceController::class, 'clearCaches']);

        Route::get('/sandbox/users', [SandboxController::class, 'listUsers']);
        Route::post('/sandbox/users', [SandboxController::class, 'createUser']);
        Route::delete('/sandbox/users/{uniId}', [SandboxController::class, 'deleteUser']);
        Route::delete('/sandbox/users/{uniId}/positions', [SandboxController::class, 'clearPositions']);
        Route::post('/sandbox/positions', [SandboxController::class, 'insertPositions']);

        // Invoice scenario runner: each case resets a throwaway account, seeds
        // real trades/transfers, invoices through InvoiceService and asserts the
        // figures. Runs only ever write to the `SBXINV-` scratch account.
        Route::get('/sandbox/invoice-scenarios', [SandboxInvoiceController::class, 'catalogue']);
        Route::post('/sandbox/invoice-scenarios/run', [SandboxInvoiceController::class, 'run']);
        Route::delete('/sandbox/users/{uniId}/invoices', [SandboxInvoiceController::class, 'clearInvoices']);
        Route::delete('/sandbox/users/{uniId}/scenario-account', [SandboxInvoiceController::class, 'clearScenarioData']);

        // phpMyAdmin-style DB console. The {table} constraint is load-bearing:
        // it makes a dotted cross-schema reference (`napai_db.users`) unroutable
        // before the controller runs. Row keys travel in the BODY, not the path,
        // because PKs here range over int `id`, UUID `uni_id`, `key` and `email`
        // — a path segment cannot express a composite key or a NULL part.
        //
        // Developer-only: arbitrary reads and writes over every table are a
        // maintenance tool, so admin/master do not inherit it.
        Route::middleware('developer')->group(function () {
            Route::get('/database/tables', [AdminDatabaseController::class, 'tables']);
            Route::get('/database/tables/{table}/structure', [AdminDatabaseController::class, 'structure'])->where('table', '[A-Za-z0-9_]{1,64}');
            Route::get('/database/tables/{table}/rows', [AdminDatabaseController::class, 'rows'])->where('table', '[A-Za-z0-9_]{1,64}');
            Route::post('/database/tables/{table}/rows', [AdminDatabaseController::class, 'storeRow'])->where('table', '[A-Za-z0-9_]{1,64}');
            Route::put('/database/tables/{table}/rows', [AdminDatabaseController::class, 'updateRow'])->where('table', '[A-Za-z0-9_]{1,64}');
            Route::delete('/database/tables/{table}/rows', [AdminDatabaseController::class, 'destroyRow'])->where('table', '[A-Za-z0-9_]{1,64}');
            Route::post('/database/query', [AdminDatabaseController::class, 'query']);
        });
    });

    Route::prefix('exchange')->group(function () {
        Route::get('/accounts', [ExchangeAccountController::class, 'index']);
        Route::post('/binance', [ExchangeAccountController::class, 'storeBinance']);
        Route::put('/accounts/{id}', [ExchangeAccountController::class, 'update']);
        Route::delete('/accounts/{id}', [ExchangeAccountController::class, 'destroy']);
    });
});

// Payment provider callbacks. Outside auth:sanctum for the same reason
// /api/engine/* is: the caller is a machine with no user token, and it
// authenticates by proving it holds the shared webhook secret (Stripe's HMAC
// header / Coinsbuy's meta.sign). Throttling is disabled because a provider
// retry burst must never come back as a 429 — Stripe treats any non-2xx as a
// failure, retries for three days, then disables the endpoint.
Route::prefix('payments')
    ->withoutMiddleware([ThrottleRequests::class])
    ->group(function () {
        Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])
            ->middleware('stripe.webhook');
        Route::post('/coinsbuy/webhook', [CoinsbuyWebhookController::class, 'handle'])
            ->middleware('coinsbuy.webhook');
    });

// Machine-to-machine surface for the Python trading engine. No user token —
// authenticated by X-Engine-Secret (`engine` middleware). Exchange-scoped so
// Bybit/MEXC engines plug in without new routes; throttling is disabled
// because pollers legitimately exceed the per-IP API limiter.
Route::prefix('engine/{exchange}')
    ->whereIn('exchange', ['binance', 'bybit', 'mexc'])
    ->middleware('engine')
    ->withoutMiddleware([ThrottleRequests::class])
    ->group(function () {
        Route::get('/accounts', [EngineController::class, 'accounts']);
        Route::get('/assets', [EngineController::class, 'assets']);
        Route::post('/trade-logs', [EngineController::class, 'storeTradeLog']);
        Route::get('/open-strategies', [EngineController::class, 'openStrategies']);
        Route::post('/open-strategies', [EngineController::class, 'storeOpenStrategy']);
        Route::delete('/open-strategies', [EngineController::class, 'destroyOpenStrategy']);

        Route::post('/positions/sync', [EngineSyncController::class, 'syncPositions']);
        Route::post('/positions/upsert', [EngineSyncController::class, 'upsertPosition']);
        Route::get('/positions/check', [EngineSyncController::class, 'checkPositions']);
        Route::post('/past-positions/sync', [EngineSyncController::class, 'syncPastPositions']);
        Route::post('/balances', [EngineSyncController::class, 'updateBalances']);
        Route::post('/transactions', [EngineSyncController::class, 'insertTransactions']);
    });
