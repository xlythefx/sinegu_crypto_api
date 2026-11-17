<?php

use App\Http\Controllers\AdminAiAccessController;
use App\Http\Controllers\AdminApiKeyController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminCustomerActivityController;
use App\Http\Controllers\AdminCreativeAgentController;
use App\Http\Controllers\AdminCreativeAiController;
use App\Http\Controllers\AdminCreativeAudioController;
use App\Http\Controllers\AdminCreativeBriefController;
use App\Http\Controllers\AdminCreativeCharacterController;
use App\Http\Controllers\AdminCreativeGenerationController;
use App\Http\Controllers\AdminCreativeMemoryController;
use App\Http\Controllers\AdminCreativePlanController;
use App\Http\Controllers\AdminCreativePlaybookController;
use App\Http\Controllers\AdminCreativeStyleController;
use App\Http\Controllers\AdminCreativeVideoController;
use App\Http\Controllers\AdminCreativesController;
use App\Http\Controllers\AdminDatabaseController;
use App\Http\Controllers\AdminEngineController;
use App\Http\Controllers\AdminInsightsController;
use App\Http\Controllers\AdminInvoiceController;
use App\Http\Controllers\AdminMaintenanceController;
use App\Http\Controllers\AdminManualTradeController;
use App\Http\Controllers\AdminOpenPositionsController;
use App\Http\Controllers\EngineInvoiceController;
use App\Http\Controllers\AdminReferralController;
use App\Http\Controllers\AdminSignupInfoController;
use App\Http\Controllers\AdminTodoController;
use App\Http\Controllers\AdminTradeLogController;
use App\Http\Controllers\AdminTronTransferController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AdminVisitorController;
use App\Http\Controllers\AdminWebhookController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CoinsbuyWebhookController;
use App\Http\Controllers\ContactHandleController;
use App\Http\Controllers\CreativeAssetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiscordAuthController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\EngineController;
use App\Http\Controllers\EngineSyncController;
use App\Http\Controllers\ExchangeAccountController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\McpController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PayoutMethodController;
use App\Http\Controllers\PositionRefreshController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicMarketController;
use App\Http\Controllers\PublicStatsController;
use App\Http\Controllers\PublicVisitController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\SandboxController;
use App\Http\Controllers\SandboxEmailController;
use App\Http\Controllers\SandboxInvoiceController;
use App\Http\Controllers\StrategyController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

// Marketing site — unauthenticated. Percentages and counts only, never
// balances or amounts (see PublicStatsController's privacy rule). Server-side
// cached, so the landing page's traffic never turns into query load — but the
// `symbols` filter is part of the cache key and caller-chosen, so an unknown
// ticker per request would be a fresh computation each time. 60/min per IP is
// far above one call per page view and keeps that from becoming query load.
Route::prefix('public')->middleware('throttle:60,1')->group(function () {
    Route::get('/track-record', [PublicStatsController::class, 'trackRecord']);
    // One exchange's slice of the same record — what the Telegram recaps post,
    // one message per exchange. Unknown names 404 (no query, nothing to leak).
    Route::get('/track-record/{exchange}', [PublicStatsController::class, 'trackRecordForExchange'])
        ->whereIn('exchange', \App\Services\Exchanges\ExchangeSchema::supported());
    // The landing page's quote strip. Binance's own prices, proxied and cached
    // 30s — nothing of ours, so the privacy rule above has nothing to bite on.
    // Takes no input at all: the symbols are config, so the cache is one key
    // and a visitor cannot make us fetch anything we did not choose to fetch.
    Route::get('/market-ticker', [PublicMarketController::class, 'ticker']);
    // The hero's live order book — same proxy, a much shorter cache, because a
    // book that refreshes on the strip's 30s is a picture of a book.
    Route::get('/order-book', [PublicMarketController::class, 'orderBook']);
});

// The visitor counter's ping from a public page (VisitRecorder decides what
// counts; always 204). Outside the group above so it never spends the
// landing page's 60/min. Its own limiter (120/min per IP) is only the
// backstop that keeps a flood from reaching PHP: VisitRecorder's own flood
// rule (30/min) trips first and marks the IP suspicious.
Route::post('/public/visits', [PublicVisitController::class, 'store'])
    ->middleware('throttle:visits');

// The four unauthenticated writes carry NAMED limiters
// (AppServiceProvider::configureRateLimiting). Each pairs the old per-IP
// limit with a per-EMAIL one that counts whoever sends the request, because
// a per-IP limit alone is walked past by rotating addresses. Login 10/min per
// IP + 5/min per account — an account here holds exchange API keys, so login
// is what credential stuffing aims at; 10 is still generous for a person
// mistyping. Register 5/min per IP: a bot creating accounts has no
// legitimate rate at all. Forgot is the lowest (3/min per IP, 2/min per
// email): it SENDS MAIL, and its controller adds a 60 s per-account cooldown
// and carries the guess count across a reissue. Reset (10/min each) sits
// ABOVE its per-code attempt cap (PasswordResetController::MAX_ATTEMPTS, 5)
// on purpose — the cap is what voids a guessed-at code, and it must be
// reachable before the throttle hides it; the throttle is the backstop
// against hammering many codes.
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:register');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');
    Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])
        ->middleware('throttle:forgot-password');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:reset-password');

    // "Sign in with Discord". config is read on every /auth page view and
    // carries nothing secret (60/min, like /public). callback and
    // link-with-password are LOGINS — the latter takes a password guess, so
    // both sit on login's 10/min beside the per-token attempt cap. complete
    // creates an account and gets register's 5/min.
    Route::prefix('discord')->group(function () {
        Route::get('/config', [DiscordAuthController::class, 'config'])
            ->middleware('throttle:60,1');
        Route::post('/callback', [DiscordAuthController::class, 'callback'])
            ->middleware('throttle:10,1');
        Route::post('/link-with-password', [DiscordAuthController::class, 'linkWithPassword'])
            ->middleware('throttle:10,1');
        Route::post('/complete', [DiscordAuthController::class, 'complete'])
            ->middleware('throttle:5,1');
    });

    // The only signed-in routes an UNVERIFIED account may call: who am I, sign
    // out, and the code screen itself. Verify sits above its per-code attempt
    // cap (EmailVerification::MAX_ATTEMPTS, 5) for the same reason
    // reset-password does; resend SENDS MAIL, so it also carries a 60 s
    // server-side cooldown (409 RESEND_TOO_SOON).
    // Sign-out alone is open to a SUSPENDED account: dropping your own token
    // must always work. Everything else signed-in — these three included —
    // sits behind account.active (EnsureAccountActive → 403 ACCOUNT_SUSPENDED).
    Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

    Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);

        Route::post('/email/verify', [EmailVerificationController::class, 'verify'])
            ->middleware('throttle:10,1');
        Route::post('/email/resend', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:3,1');
    });
});

// Everything else signed-in requires an account that is not suspended
// (EnsureAccountActive → 403 ACCOUNT_SUSPENDED) and a verified email
// (EnsureEmailVerified → 403 EMAIL_UNVERIFIED), the admin groups nested below
// included.
Route::middleware(['auth:sanctum', 'account.active', 'email.verified'])->group(function () {
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

        // Direct USDT-TRC20. The network a caller gets is decided server-side
        // from their role — a client-supplied one would let a trader settle a
        // real invoice with testnet tokens.
        Route::post('/tron/intent', [PaymentController::class, 'tronIntent'])
            ->middleware('throttle:12,1');
        // Higher limit than its siblings on purpose: the pay sheet polls this
        // every 10 s while it waits for the chain, and two open tabs would trip
        // 12/min on their own.
        Route::get('/tron/intent/{invoiceId}', [PaymentController::class, 'tronIntentStatus'])
            ->whereNumber('invoiceId')
            ->middleware('throttle:60,1');
        // "That held payment is mine" — the customer's transaction ID settles
        // a payment the watcher could not place, or opens a dispute when it is
        // already on someone else's invoice (TronPaymentClaims).
        Route::post('/tron/intent/{invoiceId}/claim', [PaymentController::class, 'tronClaim'])
            ->whereNumber('invoiceId')
            ->middleware('throttle:10,1');
        // Settles an invoice WITHOUT money — the developer test button. Two
        // independent gates: this middleware (role === 'developer' exactly,
        // NOT satisfied by admin or master) and, in the controller,
        // tronIsSimulatable(), which refuses the network that carries real
        // money. Never relax either one.
        Route::post('/tron/intent/{invoiceId}/simulate', [PaymentController::class, 'tronSimulate'])
            ->whereNumber('invoiceId')
            ->middleware(['developer', 'throttle:12,1']);
    });

    Route::prefix('user')->group(function () {
        Route::put('/profile', [ProfileController::class, 'updateProfile']);
        Route::delete('/email/pending', [ProfileController::class, 'cancelPendingEmail']);
        Route::post('/image', [ProfileController::class, 'uploadImage']);
        Route::put('/password', [ProfileController::class, 'updatePassword']);
        // The first password of a Discord-only account (refused once one exists).
        Route::post('/password/set', [ProfileController::class, 'setPassword']);
        // Settings → Connect / Disconnect Discord for a signed-in user.
        Route::post('/discord/link', [DiscordAuthController::class, 'link'])
            ->middleware('throttle:10,1');
        Route::delete('/discord', [DiscordAuthController::class, 'unlink']);
        // Settings → the optional Telegram / Discord usernames given at sign-up.
        Route::get('/contacts', [ContactHandleController::class, 'show']);
        Route::put('/contacts', [ContactHandleController::class, 'update']);
        // A tester's "Fetch" on /dashboard/positions (tester role checked in
        // the controller, plus its own 15 s per-user cooldown).
        Route::post('/positions/refresh', [PositionRefreshController::class, 'refresh'])
            ->middleware('throttle:10,1');
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

    // Staff reads — the ONLY admin endpoints a read-only `collaborator` may
    // reach (EnsureStaff = EnsureAdmin::ROLES + collaborator): the dashboard
    // Overview, User Management (list + detail) and Strategies. GETs only;
    // every write and every other read stays in the `admin` group below, so a
    // route added there is closed to collaborators by default. The shared
    // controllers redact fee settings and account / key details for them
    // (EnsureStaff::isLimited). Each URI here must NOT also be registered in
    // the admin group — a later identical route would silently replace it.
    Route::prefix('admin')->middleware('staff')->group(function () {
        Route::get('/insights/overview', [AdminInsightsController::class, 'overview']);
        Route::get('/insights/platform', [AdminInsightsController::class, 'platform']);
        Route::get('/insights/platform/daily-pnl', [AdminInsightsController::class, 'platformDailyPnl']);

        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/users/{uniId}', [AdminUserController::class, 'show']);
        Route::get('/users/{uniId}/summary', [AdminUserController::class, 'summary']);
        Route::get('/users/{uniId}/daily-pnl', [AdminUserController::class, 'dailyPnl']);
        Route::get('/users/{uniId}/analytics', [AnalyticsController::class, 'forUser']);
        // Sign-up details: sources, handles, register + login fingerprints.
        // Every staff role, IP included (owner, 2026-10-14).
        Route::get('/users/{uniId}/signup-info', [AdminSignupInfoController::class, 'show']);

        // The Strategies list AND the strategy detail page both read only this.
        Route::get('/strategies', [StrategyController::class, 'index']);

        // Admin → Visitors: the public website's traffic. Every staff role,
        // IP included (owner, 2026-10-28).
        Route::get('/visitors/summary', [AdminVisitorController::class, 'summary']);
        Route::get('/visitors/log', [AdminVisitorController::class, 'log']);
    });

    // Admin → Creatives — THE ONE ADMIN AREA A COLLABORATOR MAY WRITE (owner,
    // 2026-11-10: collaborators are the marketing team). They plan X / TikTok
    // posts and generate images and videos with Higgsfield here, so this group
    // is `staff`, writes included. It must never grow a non-creative route:
    // anything else a collaborator could write here would bypass the
    // read-only rule the staff group above exists for. The one creative
    // write they may NOT make — the monthly cap — is in the `admin` group.
    Route::prefix('admin/creatives')->middleware('staff')->group(function () {
        Route::get('/', [AdminCreativesController::class, 'index']);
        Route::get('/budget', [AdminCreativesController::class, 'budget']);

        Route::get('/plans', [AdminCreativePlanController::class, 'index']);
        Route::post('/plans', [AdminCreativePlanController::class, 'store']);
        Route::put('/plans/{id}', [AdminCreativePlanController::class, 'update'])->whereNumber('id');
        Route::delete('/plans/{id}', [AdminCreativePlanController::class, 'destroy'])->whereNumber('id');

        Route::get('/character', [AdminCreativeCharacterController::class, 'show']);
        Route::put('/character', [AdminCreativeCharacterController::class, 'update']);
        Route::post('/character/refs', [AdminCreativeCharacterController::class, 'storeRef'])->middleware('throttle:20,1');
        Route::post('/character/refs/from-generation', [AdminCreativeCharacterController::class, 'storeRefFromGeneration'])
            ->middleware('throttle:20,1');
        Route::delete('/character/refs/{id}', [AdminCreativeCharacterController::class, 'destroyRef'])->whereNumber('id');

        // The list refreshes running rows at Higgsfield before it answers.
        Route::get('/generations', [AdminCreativeGenerationController::class, 'index']);
        // Free price check — nothing is started.
        Route::post('/generations/estimate', [AdminCreativeGenerationController::class, 'estimate'])->middleware('throttle:60,1');
        // Spends money, within the monthly cap (CreativeBudget, under a row lock).
        Route::post('/generations', [AdminCreativeGenerationController::class, 'store'])->middleware('throttle:20,1');
        Route::post('/generations/{id}/cancel', [AdminCreativeGenerationController::class, 'cancel'])->whereNumber('id');

        // The AI writer (Claude): a draft from a brief, and post ideas. Both
        // spend from the SAME monthly cap as generations (reserved before the
        // call, the real cost recorded after). Nothing is saved or published
        // here — the team edits the draft and saves the plan themselves.
        Route::post('/ai/draft', [AdminCreativeAiController::class, 'draft'])->middleware('throttle:20,1');
        Route::post('/ai/ideas', [AdminCreativeAiController::class, 'ideas'])->middleware('throttle:10,1');

        // The chat agent (Claude with tools). Client-stepped: one request = at
        // most ONE model call (Cloudflare cuts a request at 100 s). Every call
        // spends from the same monthly cap. Chats are shared by the whole team
        // (each message names its author). The agent never spends on Higgsfield by itself: it
        // proposes a job and a person clicks Go (approve).
        Route::get('/agent/session', [AdminCreativeAgentController::class, 'session']);
        // The team's chats (shared since 2026-11-17).
        Route::get('/agent/sessions', [AdminCreativeAgentController::class, 'sessions']);
        Route::post('/agent/sessions', [AdminCreativeAgentController::class, 'newSession'])->middleware('throttle:20,1');
        Route::post('/agent/turn', [AdminCreativeAgentController::class, 'turn'])->middleware('throttle:40,1');
        Route::post('/agent/jobs/{id}/approve', [AdminCreativeAgentController::class, 'approve'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('/agent/jobs/{id}/cancel', [AdminCreativeAgentController::class, 'cancel'])->whereNumber('id');
        // Read-only: what the agent is told (system, tools, docs, the [Context]).
        Route::get('/agent/context', [AdminCreativeAgentController::class, 'context']);

        // The brand brief and the team memory the agent always reads.
        Route::get('/brief', [AdminCreativeBriefController::class, 'show']);
        Route::put('/brief', [AdminCreativeBriefController::class, 'update']);
        Route::get('/memory', [AdminCreativeMemoryController::class, 'index']);
        Route::post('/memory', [AdminCreativeMemoryController::class, 'store']);
        Route::put('/memory/{id}', [AdminCreativeMemoryController::class, 'update'])->whereNumber('id');
        Route::delete('/memory/{id}', [AdminCreativeMemoryController::class, 'destroy'])->whereNumber('id');

        // Creatives v2 (2026-11-12): the video playbook and the style library
        // the agent follows, the video editor's projects, and the audio library.
        Route::get('/playbook', [AdminCreativePlaybookController::class, 'show']);
        Route::put('/playbook', [AdminCreativePlaybookController::class, 'update']);
        Route::get('/styles', [AdminCreativeStyleController::class, 'index']);
        Route::post('/styles', [AdminCreativeStyleController::class, 'store']);
        Route::put('/styles/{id}', [AdminCreativeStyleController::class, 'update'])->whereNumber('id');
        Route::delete('/styles/{id}', [AdminCreativeStyleController::class, 'destroy'])->whereNumber('id');

        Route::get('/videos', [AdminCreativeVideoController::class, 'index']);
        Route::post('/videos', [AdminCreativeVideoController::class, 'store']);
        // The read refreshes the video's running clips first.
        Route::get('/videos/{id}', [AdminCreativeVideoController::class, 'show'])->whereNumber('id');
        // Every attempt at its scenes: made / failed and why, how long, cost, who.
        Route::get('/videos/{id}/history', [AdminCreativeVideoController::class, 'history'])->whereNumber('id');
        Route::put('/videos/{id}', [AdminCreativeVideoController::class, 'update'])->whereNumber('id');
        Route::delete('/videos/{id}', [AdminCreativeVideoController::class, 'destroy'])->whereNumber('id');
        Route::post('/videos/{id}/scenes', [AdminCreativeVideoController::class, 'addScene'])->whereNumber('id');
        // Free price checks; nothing is started (Go is the job approve above).
        Route::post('/videos/{id}/scenes/propose', [AdminCreativeVideoController::class, 'propose'])->whereNumber('id')->middleware('throttle:20,1');
        Route::delete('/videos/{id}/scenes/{sceneId}', [AdminCreativeVideoController::class, 'destroyScene'])->whereNumber(['id', 'sceneId']);
        // The MP4 is made in the browser and never stored: metadata only.
        Route::post('/videos/{id}/exported', [AdminCreativeVideoController::class, 'exported'])->whereNumber('id');

        Route::get('/audio', [AdminCreativeAudioController::class, 'index']);
        Route::post('/audio', [AdminCreativeAudioController::class, 'store'])->middleware('throttle:20,1');
        Route::delete('/audio/{id}', [AdminCreativeAudioController::class, 'destroy'])->whereNumber('id');
    });

    Route::prefix('admin')->middleware('admin')->group(function () {
        // Admin → AI Access: keys for the read-only AI connector (POST /mcp)
        // and the log of every question it asked. Full admins only — a key
        // reads what an admin reads, billing included.
        Route::get('/ai-access', [AdminAiAccessController::class, 'index']);
        Route::get('/ai-access/log', [AdminAiAccessController::class, 'log']);
        Route::post('/ai-access/keys', [AdminAiAccessController::class, 'store'])->middleware('throttle:10,1');
        Route::delete('/ai-access/keys/{id}', [AdminAiAccessController::class, 'destroy'])->whereNumber('id');
        // May this key make images / clips and Idea plans in Creatives (off by default).
        Route::put('/ai-access/keys/{id}', [AdminAiAccessController::class, 'update'])->whereNumber('id');

        // Admin → Creatives' monthly spending cap: any full admin sets it; the
        // marketing team (collaborators) only sees it (GET is in the
        // admin/creatives staff group above). 0 switches generation off.
        Route::put('/creatives/budget', [AdminCreativesController::class, 'updateBudget']);
        // …and whether the AI writer drafts a weekly recap every Saturday.
        // Collaborators see the state (GET /admin/creatives → `ai`), only a
        // full admin switches it.
        Route::put('/creatives/ai/settings', [AdminCreativeAiController::class, 'updateSettings']);

        // Admin → To be Done: the owner's done-state per item slug. The items
        // themselves ship with the frontend, so the slug is shape-checked only.
        Route::get('/todos', [AdminTodoController::class, 'index']);
        Route::put('/todos/{slug}', [AdminTodoController::class, 'update'])
            ->where('slug', '(?=.{1,80}$)[a-z0-9]+(?:-[a-z0-9]+)*');

        Route::get('/master-stats', [AdminController::class, 'masterStats']);
        Route::get('/daily-pnl', [AdminController::class, 'dailyPnl']);
        Route::get('/performance', [AdminController::class, 'performance']);
        Route::get('/positions', [AdminController::class, 'positions']);
        // Real exchange reads (one per account), so throttled per admin.
        Route::post('/positions/refresh', [AdminController::class, 'refreshPositions'])->middleware('throttle:6,1');
        Route::put('/positions/{id}', [AdminController::class, 'updatePosition']);
        Route::put('/past-positions/{id}', [AdminController::class, 'updatePastPosition']);
        Route::delete('/positions/{id}', [AdminController::class, 'deletePosition']);
        Route::delete('/past-positions/{id}', [AdminController::class, 'deletePastPosition']);
        // Admin Dashboard → Open positions: every venue, a forced exchange
        // read (30 s platform-wide cooldown, in the controller) and a manual
        // close through the engine's exit path.
        Route::get('/open-positions', [AdminOpenPositionsController::class, 'index']);
        Route::post('/open-positions/refresh', [AdminOpenPositionsController::class, 'refresh']);
        Route::post('/open-positions/close', [AdminOpenPositionsController::class, 'close'])->middleware('throttle:10,1');
        // GET /users, /users/{uniId}{,/summary,/daily-pnl,/analytics} live in
        // the staff group above.
        Route::post('/users', [AdminUserController::class, 'store']);
        Route::post('/users/{uniId}/accept', [AdminController::class, 'acceptUser']);
        Route::post('/users/{uniId}/reject', [AdminController::class, 'rejectUser']);
        Route::get('/users/{uniId}/positions', [AdminUserController::class, 'positions']);
        Route::get('/users/{uniId}/invoices', [AdminUserController::class, 'invoices']);
        // The admin audit (2026-11-11): a customer's timeline, notes, and the
        // bot's last signals for them. Full admins only.
        Route::get('/users/{uniId}/timeline', [AdminCustomerActivityController::class, 'timeline']);
        Route::get('/users/{uniId}/signals', [AdminCustomerActivityController::class, 'signals']);
        Route::post('/users/{uniId}/notes', [AdminCustomerActivityController::class, 'addNote']);
        Route::delete('/users/{uniId}/notes/{id}', [AdminCustomerActivityController::class, 'deleteNote'])->whereNumber('id');

        // Admin dashboard tabs — read-only aggregates, cached a minute.
        // overview / platform / platform/daily-pnl are in the staff group.
        Route::get('/insights/customers', [AdminInsightsController::class, 'customers']);
        Route::get('/insights/money', [AdminInsightsController::class, 'money']);
        Route::get('/insights/system', [AdminInsightsController::class, 'system']);
        Route::get('/insights/strategies', [AdminInsightsController::class, 'strategies']);
        Route::put('/users/{uniId}', [AdminUserController::class, 'update']);
        Route::delete('/users/{uniId}', [AdminUserController::class, 'destroy']);
        Route::post('/users/{uniId}/refresh', [AdminUserController::class, 'refresh'])->middleware('throttle:10,1');

        // GET /strategies is in the staff group; the toggle is admin-only.
        Route::put('/strategies/{key}', [StrategyController::class, 'setEnabled']);

        Route::get('/assets', [AssetController::class, 'index']);
        Route::get('/assets/history', [AssetController::class, 'history']);
        // The form's Binance check: minimum order, mark price, smallest account.
        Route::get('/assets/binance-check', [AssetController::class, 'binanceCheck'])->middleware('throttle:30,1');
        Route::post('/assets/position-limit', [AssetController::class, 'positionLimit'])->middleware('throttle:30,1');
        Route::post('/assets', [AssetController::class, 'store']);
        Route::put('/assets/{asset}', [AssetController::class, 'update']);
        Route::delete('/assets/{asset}', [AssetController::class, 'destroy']);
        // Streak Sizing Settings tab: write the ladder alone / which run each account is on now.
        Route::put('/assets/{asset}/streak-sizing', [AssetController::class, 'updateStreakSizing']);
        Route::get('/assets/{asset}/streaks', [AssetController::class, 'streaks']);
        // A size change waiting for the open position to close (PendingSizing).
        Route::post('/assets/{asset}/pending-sizing/apply', [AssetController::class, 'applyPending']);
        Route::delete('/assets/{asset}/pending-sizing', [AssetController::class, 'cancelPending']);

        // Engine signal log (read-only) — what the engine did per signal, incl.
        // the balance-proportional sizing decision per account.
        Route::get('/trade-logs', [AdminTradeLogController::class, 'index']);
        // Health banner + problems grouped by cause (SignalHealth). Before {id}.
        Route::get('/trade-logs/health', [AdminTradeLogController::class, 'health']);
        Route::get('/trade-logs/issues', [AdminTradeLogController::class, 'issues']);
        // TradingView (main feed) vs QuantLab (test feed), paired (FeedComparison).
        Route::get('/trade-logs/compare', [AdminTradeLogController::class, 'compare']);
        Route::get('/trade-logs/{id}', [AdminTradeLogController::class, 'show']);

        Route::get('/invoices', [AdminInvoiceController::class, 'index']);
        Route::get('/invoices/{id}', [AdminInvoiceController::class, 'show']);
        Route::post('/invoices/generate', [AdminInvoiceController::class, 'generate']);
        Route::post('/invoices/manual', [AdminInvoiceController::class, 'manual']);
        Route::put('/invoices/{id}', [AdminInvoiceController::class, 'update']);
        Route::delete('/invoices/{id}', [AdminInvoiceController::class, 'destroy']);

        // Incoming USDT-TRC20, and the human decision the matcher cannot make.
        // In the `admin` group rather than the stricter `developer` one:
        // attributing a payment is strictly LESS powerful than the manual
        // mark-paid every admin already has through PUT /admin/invoices/{id}.
        // While the rail is hidden, the sidebar's developerOnly flag is what
        // keeps it out of sight — one line to flip when it goes public.
        Route::get('/tron-transfers', [AdminTronTransferController::class, 'index']);
        Route::post('/tron-transfers/{id}/attribute', [AdminTronTransferController::class, 'attribute'])
            ->whereNumber('id');
        Route::post('/tron-transfers/{id}/ignore', [AdminTronTransferController::class, 'ignore'])
            ->whereNumber('id');
        // Close a "two customers claim the same payment" alarm, with a note.
        Route::post('/tron-transfers/claims/{claimId}/resolve', [AdminTronTransferController::class, 'resolveClaim'])
            ->whereNumber('claimId');

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

        // Admin → Webhooks: every engine webhook (venue × signal feed), who it
        // reaches and whether the engine has it on. Secrets as presence only.
        Route::get('/webhooks', [AdminWebhookController::class, 'index']);

        // Bot Engine ops page: systemd state + health, journal tail, restart.
        Route::get('/engine/status', [AdminEngineController::class, 'status']);
        Route::get('/engine/logs', [AdminEngineController::class, 'logs']);
        Route::post('/engine/restart', [AdminEngineController::class, 'restart']);
        // Render a daily/weekly/monthly recap and send it to the ADMIN chat as
        // a test — never the public channel. Forwarded to the engine.
        Route::post('/engine/reports/preview', [AdminEngineController::class, 'previewReport']);

        // Accounts an exchange is refusing (Binance -2015 and friends, MEXC
        // 401/406…), with an admin-side re-test so support does not have to
        // wait for a poll. Accounts live in one table per exchange, so the
        // re-test is addressed by both — an id alone names a different row
        // on every venue.
        Route::get('/engine/key-issues', [AdminEngineController::class, 'keyIssues']);
        Route::post('/engine/key-issues/{exchange}/{id}/recheck', [AdminEngineController::class, 'recheckKey'])
            ->whereIn('exchange', ['binance', 'bybit', 'mexc'])
            ->whereNumber('id');

        // API-key inventory: every exchange account with its owner. Delete is
        // the same soft disconnect the trader performs — bulk-delete carries
        // explicit (exchange, id) pairs so it can only remove what the admin
        // actually saw. Known-but-unwired exchanges (bybit) answer 400 from
        // the controller, unknown names 404 here.
        Route::get('/api-keys', [AdminApiKeyController::class, 'index']);
        Route::post('/api-keys/bulk-delete', [AdminApiKeyController::class, 'bulkDestroy']);
        Route::prefix('api-keys/{exchange}')->whereIn('exchange', ['binance', 'bybit', 'mexc'])->group(function () {
            Route::put('/{id}', [AdminApiKeyController::class, 'update'])->whereNumber('id');
            // Transfer history from the exchange's own ledger: preview, then apply.
            Route::get('/{id}/ledger', [AdminApiKeyController::class, 'ledger'])->whereNumber('id');
            Route::post('/{id}/ledger', [AdminApiKeyController::class, 'applyLedger'])->whereNumber('id');
            Route::delete('/{id}/purge', [AdminApiKeyController::class, 'purge'])->whereNumber('id');
            Route::delete('/{id}', [AdminApiKeyController::class, 'destroy'])->whereNumber('id');
        });

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
        // Same handler with no user segment = every user's invoices. A separate
        // path, not a flag, so the global wipe can never be a typo away.
        Route::delete('/sandbox/invoices', [SandboxInvoiceController::class, 'clearInvoices']);
        Route::delete('/sandbox/users/{uniId}/scenario-account', [SandboxInvoiceController::class, 'clearScenarioData']);

        // Email templates: every email rendered with sample data, and preview
        // copies mailed to a reviewer. Throttled — each call can send a dozen.
        Route::get('/sandbox/emails', [SandboxEmailController::class, 'index']);
        Route::post('/sandbox/emails/send', [SandboxEmailController::class, 'send'])->middleware('throttle:10,1');

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
        // Every exchange's accounts, each row stamped with its `exchange`.
        Route::get('/accounts', [ExchangeAccountController::class, 'index']);

        // Accounts live in one table per exchange, so ids only mean something
        // WITH the exchange — every write is addressed by both. Known-but-
        // unwired exchanges (bybit) answer 400 from the controller, unknown
        // names 404 here.
        Route::post('/{exchange}', [ExchangeAccountController::class, 'store'])
            ->whereIn('exchange', ['binance', 'bybit', 'mexc']);
        Route::prefix('{exchange}/accounts')->whereIn('exchange', ['binance', 'bybit', 'mexc'])->group(function () {
            Route::put('/{id}', [ExchangeAccountController::class, 'update'])->whereNumber('id');
            Route::delete('/{id}', [ExchangeAccountController::class, 'destroy'])->whereNumber('id');
            // Own 60s per-account cooldown inside the controller — see refreshBalance().
            Route::post('/{id}/refresh-balance', [ExchangeAccountController::class, 'refreshBalance'])->whereNumber('id');
        });

        // Binance-only forms from before MEXC existed, kept so a client built
        // against them keeps working through a deploy. Same handlers.
        Route::put('/accounts/{id}', [ExchangeAccountController::class, 'updateBinance'])->whereNumber('id');
        Route::delete('/accounts/{id}', [ExchangeAccountController::class, 'destroyBinance'])->whereNumber('id');
        Route::post('/accounts/{id}/refresh-balance', [ExchangeAccountController::class, 'refreshBalanceBinance'])->whereNumber('id');
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
        Route::get('/streaks', [EngineController::class, 'streaks']);
        Route::post('/trade-logs', [EngineController::class, 'storeTradeLog']);
        Route::get('/open-strategies', [EngineController::class, 'openStrategies']);
        Route::post('/open-strategies', [EngineController::class, 'storeOpenStrategy']);
        Route::delete('/open-strategies', [EngineController::class, 'destroyOpenStrategy']);

        Route::post('/positions/sync', [EngineSyncController::class, 'syncPositions']);
        Route::post('/positions/upsert', [EngineSyncController::class, 'upsertPosition']);
        Route::get('/positions/check', [EngineSyncController::class, 'checkPositions']);
        Route::post('/past-positions/sync', [EngineSyncController::class, 'syncPastPositions']);
        Route::post('/balances', [EngineSyncController::class, 'updateBalances']);
        Route::post('/key-status', [EngineSyncController::class, 'keyStatus']);
        // A close nobody is coming to fix: emailed now (TradeAlerts).
        Route::post('/alerts/exit-stuck', [EngineController::class, 'exitStuck']);
        Route::post('/transactions', [EngineSyncController::class, 'insertTransactions']);
        Route::post('/ledger', [EngineSyncController::class, 'ledger']);
        Route::post('/fees', [EngineSyncController::class, 'insertFees']);
        // The engine's monthly scheduler (1st, 23:00 Asia/Manila): invoice the
        // month that just ended. Skips anything already invoiced.
        Route::post('/invoices/monthly', [EngineInvoiceController::class, 'monthly']);
        // Same hour on the 2nd/3rd (reminder emails) and the 4th (pause unpaid).
        Route::post('/invoices/remind', [EngineInvoiceController::class, 'remind']);
        Route::post('/invoices/enforce', [EngineInvoiceController::class, 'enforce']);
    });

// Admin → Creatives' files, opened by an `<img src>` or a download link — which
// cannot send a Bearer token, hence outside the auth group. The SIGNATURE is the
// authorisation: these paths exist only as short-lived signed relative paths
// handed out by the staff API (CreativePresenter: 2 h for a reference image,
// 30 min for a download). The download streams the file from Higgsfield,
// fetching only the URL recorded from Higgsfield's own status response.
Route::middleware('signed:relative')->group(function () {
    Route::get('/creatives/refs/{id}/image', [CreativeAssetController::class, 'refImage'])
        ->whereNumber('id')
        ->name('creatives.refs.image');
    Route::get('/creatives/generations/{id}/download/{index}', [CreativeAssetController::class, 'download'])
        ->whereNumber(['id', 'index'])
        ->name('creatives.generations.download');
    // An audio library file, for the editor's <audio> and its export (2 h).
    Route::get('/creatives/audio/{id}/file', [CreativeAssetController::class, 'audioFile'])
        ->whereNumber('id')
        ->name('creatives.audio.file');
});

// The read-only AI connector (Model Context Protocol over Streamable HTTP) —
// the employer's Grok app asks its questions here. No user token: a key made
// on Admin → AI Access, in the X-Api-Key header (`ai.key`). Every tool reads;
// nothing behind this route can write. GET/DELETE are 405: no server stream,
// no sessions.
Route::middleware(['throttle:ai-guard', 'ai.key'])->group(function () {
    Route::post('/mcp', [McpController::class, 'handle']);
});
Route::match(['get', 'delete'], '/mcp', [McpController::class, 'notAllowed'])->middleware('throttle:ai-guard');
