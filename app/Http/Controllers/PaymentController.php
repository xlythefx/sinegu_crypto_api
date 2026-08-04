<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\Payments\CoinsbuyGateway;
use App\Services\Payments\PaymentEnvironment;
use App\Services\Payments\PaymentEventRecorder;
use App\Services\Payments\StripeGateway;
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
        'AMOUNT_MISMATCH' => 'The client sent an amount that no longer matches the invoice row: the invoice was regenerated while a tab was open.',
        'INVOICE_NOT_FOUND' => 'The invoice exists or not, but it is not this uni_id\'s — lookups are scoped to the caller on purpose.',
    ];

    public function __construct(
        private PaymentEnvironment $env,
        private StripeGateway $stripe,
        private CoinsbuyGateway $coinsbuy,
        private PaymentEventRecorder $events,
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
            'stripe' => [
                'enabled' => $this->stripe->isConfigured(),
                'mode' => $stripe['mode'],
                'reason' => $stripeReason,
            ],
            'coinsbuy' => [
                'enabled' => $this->coinsbuy->isConfigured(),
                'mode' => $coinsbuy['mode'],
                'default_cryptocurrency' => $coinsbuy['default_crypto'],
                'cryptocurrencies' => (array) config('payments.coinsbuy.cryptocurrencies', []),
            ],
        ];

        // Developers see the wiring even on a success: which keys are set, where
        // the callbacks point, why this box resolved the way it did. That is what
        // makes a later failure readable instead of a guess.
        if ($testAccount) {
            $payload['debug'] = $this->debugEnvelope('PAYMENT_METHODS', [
                'coinsbuy' => $this->coinsbuy->diagnostics(),
                'stripe' => $this->stripeDiagnostics(),
                'frontend_base' => $this->env->frontendBaseUrl(),
            ]);
        }

        return response()->json($payload);
    }

    /**
     * POST /api/payments/stripe/checkout-session
     * Body: { invoice_id: int, amount?: float }
     */
    public function stripeCheckout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invoice_id' => ['required', 'integer'],
            'amount' => ['nullable', 'numeric'],
        ]);

        $this->applyRoleOverrides($request);

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
                502,
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
                default => ['Could not start the crypto payment. Please try again.', 502],
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
