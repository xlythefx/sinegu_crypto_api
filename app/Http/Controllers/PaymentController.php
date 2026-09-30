<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Models\TronTransfer;
use App\Services\Payments\CoinsbuyGateway;
use App\Services\Payments\PaymentEnvironment;
use App\Services\Payments\PaymentEventRecorder;
use App\Services\Payments\StripeGateway;
use App\Services\Payments\TronGateway;
use App\Services\Payments\TronIntentService;
use App\Services\Payments\TronUnits;
use App\Services\Payments\TronWatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Trader-facing payment initiation: hand back a hosted-gateway URL for an
 * invoice. Nothing here settles anything — the webhooks do that.
 *
 * The amount charged always comes from the invoice row, never from the request.
 * A client-supplied `amount` is accepted only as an advisory stale-tab guard,
 * and a client-supplied redirect URL is not accepted at all (it would be an
 * open redirect on a payment endpoint).
 */
class PaymentController extends Controller
{
    /**
     * Set by {@see applyRoleOverrides()} — true only for `developer` accounts,
     * who get the full diagnostic envelope on every failure. A trader on the
     * same box, hitting the same fault, still sees the one-line message.
     */
    private bool $devCaller = false;

    /**
     * What a developer should check first for each failure code. These are the
     * conclusions that otherwise cost an hour of log-reading; the trace below
     * them says which one actually applies.
     */
    private const HINTS = [
        'COINSBUY_UNREACHABLE' => 'Nothing answered on the provider host — DNS, blocked outbound 443, or TLS. On WAMP a cURL error 60 is the missing CA bundle: set payments.coinsbuy.ca_bundle, or verify_ssl=false locally.',
        'COINSBUY_AUTH_FAILED' => 'Coinsbuy answered but refused the credentials, so nothing downstream could be authenticated. Check the client_id/client_secret pair for THIS mode (sandbox and production are separate key sets).',
        'COINSBUY_NO_WALLET' => 'Authenticated fine, but no wallet matched: a wallet needs status 3 (Active) and either the fiat currency (Merchant) or the requested coin (Enterprise). Pin one with the mode\'s wallet_id to skip the scan.',
        'COINSBUY_ERROR' => 'Coinsbuy rejected the deposit itself — read trace[].detail. A 403 with code 2016 (or "white list") means this server\'s outbound IP is not allow-listed in the Coinsbuy dashboard.',
        'COINSBUY_NOT_CONFIGURED' => 'client_id, client_secret or base_url is empty for this mode — the *_set booleans below say which.',
        'STRIPE_NOT_CONFIGURED' => 'No Stripe secret key is set for this mode.',
        'STRIPE_INSECURE_CALLBACK' => 'Live Stripe keys need an https public API URL — the webhook cannot arrive over plaintext, so cards would be charged against invoices that never settle.',
        'STRIPE_ERROR' => 'The Stripe SDK threw while creating the session — see exception.',
        'STRIPE_LIVE_UNAVAILABLE' => 'A developer asked for a REAL card charge, but this box does not resolve to live Stripe keys: it is not the production machine, or its callback URL is not https. A trader here would be on test keys too.',
        'AMOUNT_MISMATCH' => 'The client sent an amount that no longer matches the invoice row: the invoice was regenerated while a tab was open.',
        'INVOICE_NOT_FOUND' => 'The invoice exists or not, but it is not this uni_id\'s — lookups are scoped to the caller on purpose.',
        'TRON_NOT_CONFIGURED' => 'This network has no receiving address, no token contract, or no base URL. The diagnostics below say which; set TRON_{NETWORK}_ADDRESS / _USDT_CONTRACT and clear the config cache.',
        'TRON_BAD_ADDRESS' => 'The configured address is present but FAILED ITS BASE58 CHECKSUM — a typo. It was refused rather than shown, because money sent to a mistyped address is unrecoverable and nothing downstream would notice.',
        'TRON_AMOUNT_UNAVAILABLE' => 'Another open intent already reserves this exact figure, and the amount fingerprint is off (payments.tron.fingerprint_units = 0), so there is no second figure to offer. It frees itself when that intent expires — see payments.tron.intent_ttl.',
        'TRON_NETWORK_UNKNOWN' => 'No such entry under payments.tron.networks.',
    ];

    public function __construct(
        private PaymentEnvironment $env,
        private StripeGateway $stripe,
        private CoinsbuyGateway $coinsbuy,
        private PaymentEventRecorder $events,
        private TronGateway $tron,
        private TronIntentService $tronIntents,
    ) {}

    /**
     * GET /api/payments/methods
     *
     * What the trader may pay with, and in which mode. The frontend reads its
     * default cryptocurrency and its "test mode" badge from here rather than
     * sniffing the hostname in the browser.
     */
    public function methods(Request $request): JsonResponse
    {
        $testAccount = $this->applyRoleOverrides($request);

        $stripe = $this->env->stripe();
        $coinsbuy = $this->env->coinsbuy();

        $stripeModes = $testAccount ? $this->developerStripeModes() : null;

        $stripeReason = null;
        if ($stripe['downgraded']) {
            $stripeReason = 'Card payments run in Stripe test mode until the API is served over HTTPS.';
        } elseif ($stripe['mode'] === 'test') {
            $stripeReason = 'Card payments run in Stripe test mode on this environment.';
        }

        $payload = [
            'success' => true,
            'environment' => $this->env->name(),
            // True when this caller pays with test credentials regardless of the
            // machine (developer accounts). The UI labels the buttons from this.
            'test_account' => $testAccount,
            'stripe' => array_merge([
                // A developer can pay in either mode, so the card rail is
                // there when EITHER is usable; everyone else gets the one mode
                // this box resolves to.
                'enabled' => $stripeModes !== null
                    ? ($stripeModes['test'] || $stripeModes['live'])
                    : $this->stripe->isConfigured(),
                'mode' => $stripe['mode'],
                'reason' => $stripeReason,
            ], $stripeModes !== null ? ['modes' => $stripeModes] : []),
            'coinsbuy' => [
                'enabled' => $this->coinsbuy->isConfigured(),
                'mode' => $coinsbuy['mode'],
                'default_cryptocurrency' => $coinsbuy['default_crypto'],
                'cryptocurrencies' => (array) config('payments.coinsbuy.cryptocurrencies', []),
            ],
            'default_provider' => (string) config('payments.default_provider', 'coinsbuy'),
            'tron' => $this->tronMethod($testAccount),
        ];

        // Developers see the wiring even on a success: which keys are set, where
        // the callbacks point, why this box resolved the way it did. That is what
        // makes a later failure readable instead of a guess.
        if ($testAccount) {
            $payload['debug'] = $this->debugEnvelope('PAYMENT_METHODS', [
                'coinsbuy' => $this->coinsbuy->diagnostics(),
                'stripe' => $this->stripeDiagnostics(),
                'tron' => $this->tron->diagnostics($this->env->tronNetworkFor(true)),
                'frontend_base' => $this->env->frontendBaseUrl(),
            ]);
        }

        return response()->json($payload);
    }

    /**
     * The TRON entry in GET /payments/methods.
     *
     * DELIBERATELY CARRIES NO ADDRESS. It is a public address on a public chain,
     * so exposing it leaks nothing — but while the rail is hidden behind a role,
     * "hidden" should mean hidden, and the address is only ever handed out by
     * the gated create call to someone who is about to pay.
     *
     * @return array<string, mixed>
     */
    private function tronMethod(bool $isDeveloper): array
    {
        $network = $this->env->tronNetworkFor($isDeveloper);
        $tron = $this->env->tron($network);

        return [
            'enabled' => $tron['configured'],
            // Two switches, never one: a rail is made visible to everyone well
            // before it is made the default for everyone.
            'visible' => $isDeveloper || $this->env->tronIsPublic(),
            'network' => $network,
            'asset' => $tron['asset'],
            'chain_label' => $tron['label'],
        ];
    }

    /**
     * POST /api/payments/stripe/checkout-session
     * Body: { invoice_id: int, amount?: float, mode?: 'test'|'live' }
     *
     * `mode` is honoured for `developer` accounts ONLY, and only to choose
     * between their test-card rehearsal (the default) and a REAL card charge.
     * It can never force live keys on a box that would not use them for a
     * trader anyway — "live" simply stops pinning the developer to test keys,
     * and the machine verdict (production + https) still decides. A trader's
     * `mode` is ignored: they always pay in whatever mode the box resolves.
     */
    public function stripeCheckout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invoice_id' => ['required', 'integer'],
            'amount' => ['nullable', 'numeric'],
            'mode' => ['nullable', 'in:test,live'],
        ]);

        $isDeveloper = $this->applyRoleOverrides($request);
        if ($isDeveloper && ($data['mode'] ?? null) === 'live') {
            $this->env->forceSandbox(false);
            if ($this->stripe->mode() !== 'live') {
                return $this->fail(
                    'STRIPE_LIVE_UNAVAILABLE',
                    'Real card payments are not available on this server.',
                    422,
                    ['provider' => 'stripe', 'stripe' => $this->stripeDiagnostics()]
                );
            }
        }

        $resolved = $this->resolveInvoice($request, (int) $data['invoice_id'], $data['amount'] ?? null);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        if (! $this->stripe->isConfigured()) {
            return $this->fail(
                'STRIPE_NOT_CONFIGURED',
                'Card payments are not configured on this server.',
                503,
                ['provider' => 'stripe', 'stripe' => $this->stripeDiagnostics()]
            );
        }

        // Belt and braces alongside the key-selection rule: live keys must never
        // be used against a callback URL the webhook cannot arrive on, or cards
        // get charged while invoices stay pending forever.
        if ($this->stripe->mode() === 'live' && ! $this->env->callbacksAreSecure()) {
            return $this->fail(
                'STRIPE_INSECURE_CALLBACK',
                'Card payments are unavailable until the API is served over HTTPS.',
                503,
                [
                    'provider' => 'stripe',
                    'stripe' => $this->stripeDiagnostics(),
                    'webhook_url' => $this->env->stripeWebhookUrl(),
                ]
            );
        }

        $user = $request->user();
        $customerId = $this->stripe->customerIdFor($user->uni_id, $user->email ?? null, $user->name ?? null);

        try {
            $session = $this->stripe->createCheckoutSession(
                $resolved,
                $resolved->account?->name ?? 'Account',
                $customerId
            );
        } catch (RuntimeException $e) {
            return $this->fail(
                $e->getMessage(),
                'Could not start the card payment. Please try again.',
                // 503 for the same reason as the Coinsbuy path below: an origin
                // 502 is swapped for Cloudflare's own error page at the edge.
                503,
                [
                    'provider' => 'stripe',
                    'exception' => $e->getMessage(),
                    'invoice_id' => $resolved->id,
                    'fee_cents' => $resolved->feeCents(),
                    'customer_id' => $customerId,
                    'stripe' => $this->stripeDiagnostics(),
                ]
            );
        }

        return response()->json([
            'success' => true,
            'provider' => 'stripe',
            'mode' => $this->stripe->mode(),
            'session_id' => $session['id'],
            'checkout_url' => $session['url'],
        ]);
    }

    /**
     * POST /api/payments/coinsbuy/deposit
     * Body: { invoice_id: int, cryptocurrency?: string, amount?: float }
     */
    public function coinsbuyDeposit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invoice_id' => ['required', 'integer'],
            'cryptocurrency' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric'],
        ]);

        $testAccount = $this->applyRoleOverrides($request);

        $resolved = $this->resolveInvoice($request, (int) $data['invoice_id'], $data['amount'] ?? null);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        if (! $this->coinsbuy->isConfigured()) {
            return $this->fail(
                'COINSBUY_NOT_CONFIGURED',
                'Crypto payments are not configured on this server.',
                503,
                ['provider' => 'coinsbuy', 'coinsbuy' => $this->coinsbuy->diagnostics()]
            );
        }

        $supported = (array) config('payments.coinsbuy.cryptocurrencies', []);
        $crypto = strtoupper(trim((string) ($data['cryptocurrency'] ?? '')));
        if ($crypto === '') {
            $crypto = $this->coinsbuy->defaultCryptocurrency();
        }
        if (! in_array($crypto, $supported, true)) {
            return $this->fail(
                'CRYPTOCURRENCY_UNSUPPORTED',
                'Supported cryptocurrencies: '.implode(', ', $supported).'.',
                422,
                ['requested' => $crypto, 'supported' => $supported]
            );
        }

        try {
            $deposit = $this->coinsbuy->createDeposit(
                $resolved,
                $resolved->account?->name ?? 'Account',
                $crypto
            );
        } catch (RuntimeException $e) {
            $code = $e->getMessage();

            // Never echo the underlying transport error TO A TRADER: it is a
            // cURL string ("Failed to connect … port 443"), it means nothing to
            // them, and it names our infrastructure. Developers get all of it
            // in `debug` below — same fault, two audiences.
            [$message, $status] = match ($code) {
                'COINSBUY_NO_WALLET' => [
                    'No active wallet is available for crypto payments right now.',
                    503,
                ],
                'COINSBUY_UNREACHABLE' => [
                    'The crypto payment provider is not responding right now. Please try again in a few minutes.',
                    503,
                ],
                'COINSBUY_AUTH_FAILED' => [
                    'Crypto payments are temporarily unavailable. Please try again in a few minutes.',
                    503,
                ],
                // 503, never 502/504: Cloudflare REPLACES an origin 502 with its
                // own "Bad gateway" page, body and all, so the developer `debug`
                // envelope this endpoint exists to produce never reaches the
                // browser. That is not hypothetical — a real IP-allow-list 403
                // was reported to the trader as a Cloudflare origin error, with
                // the 1.8 KB trace explaining it dropped at the edge.
                default => ['Could not start the crypto payment. Please try again.', 503],
            };

            return $this->fail($code, $message, $status, [
                'provider' => 'coinsbuy',
                'exception' => $e->getMessage(),
                'invoice_id' => $resolved->id,
                'fee_cents' => $resolved->feeCents(),
                'cryptocurrency' => $crypto,
                'coinsbuy' => $this->coinsbuy->diagnostics(),
            ]);
        }

        $amount = $resolved->feeCents() / 100;

        // Records the deposit so the callback can find the invoice by deposit id
        // as well as by tracking_id — two independent correlation paths.
        $this->events->record([
            'provider' => 'coinsbuy',
            'event_id' => 'created:'.$deposit['deposit_id'],
            'external_id' => $deposit['deposit_id'],
            'tracking_id' => $deposit['tracking_id'],
            'invoice_id' => $resolved->id,
            'user_id' => $resolved->user_id,
            'account_id' => $resolved->account_id,
            'outcome' => 'created',
            'provider_status' => $deposit['status'],
            'expected_amount' => $amount,
            'amount_currency' => config('payments.coinsbuy.fiat_currency', 'USD'),
            'crypto_currency' => $crypto,
        ]);

        return response()->json([
            'success' => true,
            'provider' => 'coinsbuy',
            'mode' => $this->coinsbuy->mode(),
            'test_account' => $testAccount,
            'deposit_id' => $deposit['deposit_id'],
            'tracking_id' => $deposit['tracking_id'],
            'payment_type' => $deposit['payment_type'],
            'payment_url' => $deposit['payment_url'],
            'destination' => $deposit['destination'],
            'cryptocurrency' => $crypto,
            'amount' => $amount,
            'currency' => config('payments.coinsbuy.fiat_currency', 'USD'),
        ]);
    }

    /**
     * POST /api/payments/tron/intent
     * Body: { invoice_id: int, amount?: float }
     *
     * Reserves an exact USDT-TRC20 figure for this invoice and hands back the
     * address to send it to. Unlike the two gateway endpoints above, THIS MAKES
     * NO OUTBOUND CALL — it is a database write against public configuration, so
     * it cannot fail on transport and there is no TRON_UNREACHABLE at pay time.
     * Only the scheduled watcher ever touches the network.
     *
     * The network is derived here from the caller's role and never read from the
     * request: a trader who could name their own network would settle a real
     * invoice with worthless testnet tokens.
     */
    public function tronIntent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invoice_id' => ['required', 'integer'],
            'amount' => ['nullable', 'numeric'],
        ]);

        $isDeveloper = $this->applyRoleOverrides($request);

        // 404 rather than 403 while the rail is hidden — indistinguishable from
        // "no such endpoint", so probing tells a stranger nothing.
        if (! $isDeveloper && ! $this->env->tronIsPublic()) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $resolved = $this->resolveInvoice($request, (int) $data['invoice_id'], $data['amount'] ?? null);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $network = $this->env->tronNetworkFor($isDeveloper);

        try {
            $intent = $this->tronIntents->openFor($resolved, $network);
        } catch (RuntimeException $e) {
            $code = $e->getMessage();

            // 503 for configuration faults, never 502/504: Cloudflare replaces
            // an origin 502 body with its own page and the envelope is lost.
            return $this->fail(
                $code,
                $code === 'TRON_AMOUNT_UNAVAILABLE'
                    ? 'This amount is temporarily reserved. Please try again in a few minutes.'
                    : 'Direct crypto payments are not available on this server yet.',
                $code === 'TRON_AMOUNT_UNAVAILABLE' ? 409 : 503,
                ['provider' => 'tron', 'tron' => $this->tron->diagnostics($network)]
            );
        }

        return response()->json($this->tronIntentPayload($intent, $network, $isDeveloper));
    }

    /**
     * GET /api/payments/tron/intent/{invoiceId}
     *
     * Polled by the pay sheet while it waits for the chain.
     *
     * DELIBERATELY DOES NOT USE resolveInvoice(): that guard 409s on an
     * already-paid invoice, which is precisely the state this endpoint exists to
     * observe. It answers 200 for a paid invoice and 404 only when the invoice
     * is not this caller's.
     */
    public function tronIntentStatus(Request $request, int $invoiceId): JsonResponse
    {
        $isDeveloper = $this->applyRoleOverrides($request);

        $invoice = Invoice::forUser($request->user()->uni_id)->find($invoiceId);
        if (! $invoice) {
            return $this->fail('INVOICE_NOT_FOUND', 'Invoice not found.', 404);
        }

        $network = $this->env->tronNetworkFor($isDeveloper);
        $tron = $this->env->tron($network);

        $intent = PaymentIntent::forNetwork($network)
            ->where('invoice_id', $invoice->id)
            ->orderByDesc('id')
            ->first();

        $transfer = $intent?->tx_hash !== null
            ? TronTransfer::where('tx_hash', $intent->tx_hash)->first()
            : TronTransfer::where('invoice_id', $invoice->id)->orderByDesc('id')->first();

        return response()->json([
            'success' => true,
            'invoice_id' => $invoice->id,
            'invoice_status' => $invoice->isPaid() ? 'paid' : 'pending',
            'intent' => $intent === null ? null : [
                'id' => $intent->id,
                'status' => $intent->status,
                'network' => $intent->network,
                'address' => $intent->address,
                'amount' => TronUnits::format((string) $intent->expected_units, (int) $intent->decimals),
                'expires_at' => $intent->expires_at?->toIso8601String(),
                'seconds_remaining' => $this->secondsRemaining($intent),
            ],
            'transfer' => $transfer === null ? null : [
                'tx_hash' => $transfer->tx_hash,
                'amount' => TronUnits::format((string) $transfer->value_units, (int) $tron['decimals']),
                'confirmed' => (bool) $transfer->confirmed,
                'status' => $transfer->status,
                'seen_at' => $transfer->created_at?->toIso8601String(),
                'explorer_url' => $tron['explorer_tx'].$transfer->tx_hash,
            ],
            // So the sheet can say "the watcher is not running" instead of
            // spinning forever. Scheduling the poller made cron load-bearing for
            // money; this is how that failure becomes visible.
            'last_scan_at' => TronGateway::lastScanAt($network)?->toIso8601String(),
            'scan_stale' => TronGateway::scanIsStale($network),
        ]);
    }

    /**
     * POST /api/payments/tron/intent/{invoiceId}/simulate
     *
     * Settle an intent as if the money had arrived — the developer test button,
     * so rehearsing the flow does not need a real testnet transfer every time.
     *
     * THIS FORGES A PAYMENT, so it is gated twice over, and both gates must be
     * kept:
     *   1. the `developer` middleware on the route (role === 'developer'
     *      exactly, not satisfied by admin or master), and
     *   2. tronIsSimulatable(), which refuses whichever network
     *      `default_network.production` names — defined against the network
     *      that carries real money rather than by hardcoded name, so renaming a
     *      network in config cannot open the door.
     *
     * It settles through the SAME TronWatcher::attribute() as a real payment,
     * so there is one settlement path rather than a second one that could drift.
     * The synthetic transfer is permanently marked: a `SIM-` transaction hash
     * (which becomes the invoice's payment_reference), `settled_by =
     * simulated:{uni_id}`, and a note on the row. A simulated payment can never
     * be mistaken for a real one in the ledger.
     */
    public function tronSimulate(Request $request, int $invoiceId, TronWatcher $watcher): JsonResponse
    {
        $isDeveloper = $this->applyRoleOverrides($request);

        $invoice = Invoice::forUser($request->user()->uni_id)->find($invoiceId);
        if (! $invoice) {
            return $this->fail('INVOICE_NOT_FOUND', 'Invoice not found.', 404);
        }
        if ($invoice->isPaid()) {
            return $this->fail('INVOICE_ALREADY_PAID', 'This invoice has already been paid.', 409);
        }

        $network = $this->env->tronNetworkFor($isDeveloper);

        if (! $this->env->tronIsSimulatable($network)) {
            return $this->fail(
                'TRON_SIMULATION_REFUSED',
                'Payments can only be simulated on a test network.',
                422,
                ['network' => $network]
            );
        }

        $intent = PaymentIntent::forNetwork($network)
            ->open()
            ->where('invoice_id', $invoice->id)
            ->orderByDesc('id')
            ->first();

        if (! $intent) {
            return $this->fail(
                'TRON_NO_OPEN_INTENT',
                'Show the payment address first, then simulate.',
                422
            );
        }

        $transfer = TronTransfer::create([
            'network' => $intent->network,
            'event_key' => hash('sha256', 'sim|'.$intent->id.'|'.microtime(true)),
            'tx_hash' => 'SIM-'.bin2hex(random_bytes(12)),
            'contract_address' => $intent->contract_address,
            'token_symbol' => $intent->asset,
            'token_decimals' => $intent->decimals,
            // Not an address on purpose — nothing sent this, and it must never
            // read as though a real wallet did.
            'from_address' => 'SIMULATED',
            'to_address' => $intent->address,
            'value_raw' => (string) $intent->expected_units,
            'value_units' => (string) $intent->expected_units,
            'block_timestamp' => now()->getTimestampMs(),
            'confirmed' => true,
            'status' => TronTransfer::STATUS_UNMATCHED,
            'note' => 'Simulated by a developer account. No funds moved.',
        ]);

        $by = 'simulated:'.$request->user()->uni_id;
        $this->tronIntents->claim($intent, $transfer->tx_hash, (string) $intent->expected_units);
        $watcher->attribute($transfer, $invoice, $intent, $by);

        return response()->json([
            'success' => true,
            'simulated' => true,
            'invoice_id' => $invoice->id,
            'network' => $network,
            'tx_hash' => $transfer->tx_hash,
            'amount' => TronUnits::format((string) $intent->expected_units, (int) $intent->decimals),
            'message' => 'Invoice settled with a simulated payment. No funds moved.',
        ]);
    }

    /** @return array<string, mixed> */
    private function tronIntentPayload(PaymentIntent $intent, string $network, bool $isDeveloper): array
    {
        $tron = $this->env->tron($network);
        $decimals = (int) $intent->decimals;

        $payload = [
            'success' => true,
            'provider' => 'tron',
            'network' => $network,
            'test_account' => $isDeveloper,
            'reused' => $intent->reused,
            'intent_id' => $intent->id,
            'invoice_id' => $intent->invoice_id,
            'asset' => $intent->asset,
            'chain_label' => $tron['label'],
            'address' => $intent->address,
            'contract_address' => $intent->contract_address,
            'decimals' => $decimals,
            // The exact figure to send, at full precision — the amount displayed
            // IS the amount matched, so a trimmed one would invite rounding.
            'amount' => TronUnits::format((string) $intent->expected_units, $decimals),
            'amount_units' => (string) $intent->expected_units,
            'usd_amount' => (float) $intent->expected_usd,
            'currency' => 'USD',
            'tolerance' => [
                'shortfall_usd' => round(TronUnits::toUsd((string) $intent->shortfall_units, $decimals), 2),
                'overpay_usd' => round(TronUnits::toUsd((string) $intent->overpay_units, $decimals), 2),
            ],
            'expires_at' => $intent->expires_at?->toIso8601String(),
            'seconds_remaining' => $this->secondsRemaining($intent),
            'status' => $intent->status,
            'explorer_url' => $tron['explorer_address'].$intent->address,
            // Whether the dev test button may appear. The server decides, and
            // enforces it again on the endpoint — the button never gets to vote.
            'simulatable' => $isDeveloper && $this->env->tronIsSimulatable($network),
        ];

        if ($isDeveloper) {
            $payload['debug'] = $this->debugEnvelope('TRON_INTENT', [
                'tron' => $this->tron->diagnostics($network),
                'expected_units' => (string) $intent->expected_units,
                'accepted_band' => [$intent->floorUnits(), $intent->ceilingUnits()],
                'fingerprint_units' => (int) config('payments.tron.fingerprint_units', 0),
            ]);
        }

        return $payload;
    }

    private function secondsRemaining(PaymentIntent $intent): int
    {
        if ($intent->expires_at === null) {
            return 0;
        }

        return max(0, now()->diffInSeconds($intent->expires_at, false));
    }

    /**
     * `developer` accounts always pay with the providers' TEST credentials, on
     * every machine — that is the whole point of the role: the real buttons, the
     * real endpoints, the real webhook, no real money.
     *
     * PaymentEnvironment is a container singleton, so pinning it here also pins
     * the gateways that were constructor-injected with it.
     *
     * @return bool whether test credentials were forced for this caller
     */
    private function applyRoleOverrides(Request $request): bool
    {
        $isDeveloper = $request->user()?->type === 'developer';
        $this->devCaller = $isDeveloper;

        // Always stated, never only switched on: the environment is a singleton,
        // so leaving a previous caller's override in place would hand the next
        // trader test keys (or, worse, leave a stale verdict in a long-lived
        // process).
        $this->env->forceSandbox($isDeveloper);

        return $isDeveloper;
    }

    /**
     * Which card modes a developer can pick on THIS box: `test` (their default)
     * and `live` (a real charge). Each is available only when that mode has a
     * secret key AND a webhook secret — no webhook, no settlement, no button.
     * Leaves the environment pinned to test afterwards, as the caller found it.
     *
     * @return array{test: bool, live: bool}
     */
    private function developerStripeModes(): array
    {
        $this->env->forceSandbox(false);
        $live = $this->stripe->mode() === 'live' && $this->stripe->isConfigured();

        $this->env->forceSandbox(true);
        $test = $this->stripe->isConfigured();

        return ['test' => $test, 'live' => $live];
    }

    /**
     * Stripe's half of the developer envelope. Key VALUES never appear — only
     * which mode was selected and whether a key exists for it.
     *
     * @return array<string, mixed>
     */
    private function stripeDiagnostics(): array
    {
        $stripe = $this->env->stripe();

        return [
            'mode' => $stripe['mode'],
            'downgraded_to_test_keys' => $stripe['downgraded'],
            'secret_set' => $stripe['secret'] !== '',
            'webhook_secret_set' => $stripe['webhook_secret'] !== '',
            'webhook_url' => $this->env->stripeWebhookUrl(),
        ];
    }

    /**
     * The shared guard. Scoping by the caller's uni_id is the point: the mother
     * looked invoices up by id alone, on an endpoint serving
     * `Access-Control-Allow-Origin: *`, so anyone could enumerate ids and read
     * back account names, periods and fees.
     */
    private function resolveInvoice(Request $request, int $id, ?float $clientAmount): Invoice|JsonResponse
    {
        $invoice = Invoice::with('account')
            ->forUser($request->user()->uni_id)
            ->find($id);

        if (! $invoice) {
            return $this->fail('INVOICE_NOT_FOUND', 'Invoice not found.', 404, [
                'requested_invoice_id' => $id,
                'scoped_to_uni_id' => $request->user()->uni_id,
            ]);
        }
        if ($invoice->isPaid()) {
            return $this->fail('INVOICE_ALREADY_PAID', 'This invoice has already been paid.', 409, [
                'invoice_id' => $invoice->id,
                'status' => $invoice->status,
            ]);
        }
        if ($invoice->feeCents() <= 0) {
            return $this->fail('NOTHING_TO_PAY', 'There is no fee due on this invoice.', 422, [
                'invoice_id' => $invoice->id,
                'fee_cents' => $invoice->feeCents(),
            ]);
        }

        // Advisory only — catches a tab left open across a regeneration.
        if ($clientAmount !== null && (int) round($clientAmount * 100) !== $invoice->feeCents()) {
            $body = [
                'success' => false,
                'error_code' => 'AMOUNT_MISMATCH',
                'message' => 'This invoice has changed. Reload the page and try again.',
                'expected' => $invoice->feeCents() / 100,
            ];

            if ($this->devCaller) {
                $body['debug'] = $this->debugEnvelope('AMOUNT_MISMATCH', [
                    'invoice_id' => $invoice->id,
                    'client_cents' => (int) round($clientAmount * 100),
                    'invoice_cents' => $invoice->feeCents(),
                ]);
            }

            return response()->json($body, 409);
        }

        return $invoice;
    }

    /**
     * @param  array<string, mixed>  $debug  extra context, developer accounts only
     */
    private function fail(string $code, string $message, int $status, array $debug = []): JsonResponse
    {
        $body = [
            'success' => false,
            'error_code' => $code,
            'message' => $message,
        ];

        if ($this->devCaller) {
            $body['debug'] = $this->debugEnvelope($code, $debug);
        }

        return response()->json($body, $status);
    }

    /**
     * The developer-only failure envelope: what the trader saw, plus why.
     *
     * Gated on the ROLE, never on the environment — the whole point of a
     * developer account is that it behaves this way on the live box too, where
     * the interesting failures actually happen. Nothing here may carry a
     * credential value; the gateways expose presence booleans instead.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function debugEnvelope(string $code, array $extra = []): array
    {
        return array_merge([
            'error_code' => $code,
            'hint' => self::HINTS[$code] ?? null,
            'environment' => $this->env->name(),
            'environment_reason' => $this->env->reason(),
            'forced_sandbox' => $this->env->sandboxIsForced(),
            'callbacks_secure' => $this->env->callbacksAreSecure(),
            'public_api_base' => $this->env->publicApiBaseUrl(),
            'at' => now()->toIso8601String(),
        ], $extra);
    }
}
