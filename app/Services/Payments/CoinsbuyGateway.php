<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Coinsbuy API v3 (JSON:API). Ported from the mother's coinsbuy/config.php +
 * create-payment.php, onto Laravel's Http + Cache.
 *
 * Coinsbuy has no metadata field, so `tracking_id` is how a callback finds its
 * way back to an invoice. Ours is `inv_{id}_{unixtime}` — the mother's
 * `billing_binance_` / `billing_ig_` broker segment is gone, because this
 * project has one `invoices` table with an `exchange` column.
 */
class CoinsbuyGateway
{
    /**
     * True once a call died before Coinsbuy answered at all (DNS, blocked 443,
     * TLS, timeout). Lets the caller say "unreachable" instead of blaming
     * credentials or a missing wallet for what is a network fault.
     */
    private bool $transportFailed = false;

    /**
     * True once Coinsbuy answered the token call but refused the credentials.
     * Without it every downstream call returns "no wallet", which sends the
     * next person hunting through the wallet dashboard for a key problem.
     */
    private bool $authFailed = false;

    /**
     * Ordered record of every provider step this request took — the raw
     * material behind {@see diagnostics()}. Bounded so a paginated wallet walk
     * cannot grow it without limit.
     *
     * @var list<array<string, mixed>>
     */
    private array $trace = [];

    private const TRACE_LIMIT = 40;

    public function __construct(private PaymentEnvironment $env) {}

    private function trace(string $step, array $detail = []): void
    {
        if (count($this->trace) >= self::TRACE_LIMIT) {
            return;
        }

        $this->trace[] = ['step' => $step] + $detail;
    }

    /**
     * Why this request went the way it did, for `developer` accounts only —
     * PaymentController is what gates it, and nothing here may ever reach a
     * trader. Credentials appear as presence booleans, never as values; the
     * only strings from Coinsbuy are its own status codes and error details.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $c = $this->env->coinsbuy();

        return [
            'mode' => $c['mode'],
            'base_url' => $c['base_url'],
            'callback_url' => $this->env->coinsbuyCallbackUrl(),
            'client_id_set' => $c['client_id'] !== '',
            'client_secret_set' => $c['client_secret'] !== '',
            'webhook_secret_set' => $c['webhook_secret'] !== '',
            'wallet_id_pinned' => $c['wallet_id'] !== '' ? $c['wallet_id'] : null,
            'downgraded_to_test_keys' => $c['downgraded'],
            'transport_failed' => $this->transportFailed,
            'auth_failed' => $this->authFailed,
            'trace' => $this->trace,
        ];
    }

    /**
     * Which failure family this call landed in. Order matters: a transport
     * fault masks everything downstream, and a token we never got masks "no
     * wallet" — the wallet scan cannot succeed without one.
     */
    private function failureCode(string $default): string
    {
        if ($this->transportFailed) {
            return 'COINSBUY_UNREACHABLE';
        }
        if ($this->authFailed) {
            return 'COINSBUY_AUTH_FAILED';
        }

        return $default;
    }

    public function mode(): string
    {
        return $this->env->coinsbuy()['mode'];
    }

    public function isConfigured(): bool
    {
        $c = $this->env->coinsbuy();

        return $c['client_id'] !== '' && $c['client_secret'] !== '' && $c['base_url'] !== '';
    }

    public function defaultCryptocurrency(): string
    {
        return $this->env->coinsbuy()['default_crypto'];
    }

    // ---- tracking id -----------------------------------------------------

    public static function trackingIdFor(int $invoiceId): string
    {
        return 'inv_'.$invoiceId.'_'.time();
    }

    /** Anchored so an unrelated tracking id can never be mistaken for ours. */
    public static function parseTrackingId(?string $trackingId): ?int
    {
        if ($trackingId !== null && preg_match('/^inv_(\d+)_\d+$/', $trackingId, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    // ---- transport -------------------------------------------------------

    /**
     * One place for every timeout and TLS knob.
     *
     * `connect_timeout` matters more than the total timeout here: when the API
     * host is unreachable (blocked egress, DNS, a firewall eating 443) cURL
     * hangs at connect and everything downstream stalls behind it.
     *
     * `verify_ssl=false` / a CA bundle path exist because WAMP ships without a
     * usable cacert.pem, which surfaces as cURL error 60 on Windows only.
     */
    private function http(): PendingRequest
    {
        $conf = (array) config('payments.coinsbuy');

        $request = Http::timeout((int) ($conf['timeout'] ?? 20))
            ->connectTimeout((int) ($conf['connect_timeout'] ?? 10))
            ->withHeaders([
                'Content-Type' => 'application/vnd.api+json',
                'Accept' => 'application/vnd.api+json',
            ]);

        // Pin egress to IPv4. The VPS holds both an A and an AAAA address and
        // Linux prefers IPv6 (RFC 6724), while the Coinsbuy hosts publish AAAA
        // records — so calls leave over IPv6 and the source address Coinsbuy
        // sees is 2a02:...::1, not the 2.24.139.176 anyone would paste into an
        // IP allow-list. Coinsbuy authorises per address, so the source has to
        // be the one deterministic address the dashboard can name; an allow-list
        // that silently governs a different protocol family is unfixable from
        // the dashboard side, because nothing there tells you which one was used.
        if (($conf['force_ipv4'] ?? true) !== false) {
            $request = $request->withOptions([
                'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
            ]);
        }

        $ca = (string) ($conf['ca_bundle'] ?? '');
        if ($ca !== '') {
            $request = $request->withOptions(['verify' => $ca]);
        } elseif (($conf['verify_ssl'] ?? true) === false) {
            $request = $request->withOptions(['verify' => false]);
        }

        return $request;
    }

    // ---- auth ------------------------------------------------------------

    /**
     * OAuth-ish JSON:API token, cached PER MODE. The mother kept one
     * .token_cache file for both environments, so flipping the environment
     * handed a sandbox token to production.
     */
    public function accessToken(bool $force = false): ?string
    {
        $c = $this->env->coinsbuy();
        $key = 'coinsbuy:token:'.$c['mode'];

        if ($force) {
            Cache::forget($key);
        } elseif ($cached = Cache::get($key)) {
            $this->trace('token.cached', ['mode' => $c['mode']]);

            return $cached;
        }

        try {
            $response = $this->http()->withBody(json_encode([
                'data' => [
                    'type' => 'auth-token',
                    'attributes' => [
                        'client_id' => $c['client_id'],
                        'client_secret' => $c['client_secret'],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES), 'application/vnd.api+json')
                ->post($c['base_url'].'/token/');
        } catch (ConnectionException $e) {
            // Connection refused / DNS / TLS / timeout. Never let the raw cURL
            // string reach the trader — it ends up rendered in the pay modal.
            $this->transportFailed = true;
            Log::error('Coinsbuy is unreachable.', [
                'mode' => $c['mode'],
                'base_url' => $c['base_url'],
                'exception' => $e->getMessage(),
            ]);
            $this->trace('token.connection_failed', [
                'url' => $c['base_url'].'/token/',
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::error('Coinsbuy token request failed.', [
                'mode' => $c['mode'],
                'status' => $response->status(),
            ]);
            $this->authFailed = true;
            $this->trace('token.rejected', [
                'status' => $response->status(),
                'code' => $response->json('errors.0.code'),
                'detail' => $response->json('errors.0.detail'),
            ]);

            return null;
        }

        $token = $response->json('data.attributes.access');
        if (! is_string($token) || $token === '') {
            Log::error('Coinsbuy token response carried no access token.', ['mode' => $c['mode']]);
            $this->authFailed = true;
            $this->trace('token.no_access_token', ['status' => $response->status()]);

            return null;
        }

        $this->trace('token.ok', ['status' => $response->status()]);

        $expiresIn = (int) ($response->json('data.attributes.expires_in') ?? 3600);
        $ttl = max(30, $expiresIn - (int) config('payments.coinsbuy.token_ttl_buffer', 60));
        Cache::put($key, $token, $ttl);

        return $token;
    }

    /**
     * One authenticated call. A 401 clears the cached token and retries exactly
     * once — a second 401 is a credentials problem, not a stale token.
     *
     * @return array{ok: bool, status: int, data: array}
     */
    public function request(string $endpoint, string $method = 'GET', ?array $body = null, bool $retried = false): array
    {
        $c = $this->env->coinsbuy();
        $token = $this->accessToken($retried);

        if ($token === null) {
            $this->trace('request.no_token', ['endpoint' => $endpoint, 'method' => $method]);

            return ['ok' => false, 'status' => 0, 'data' => []];
        }

        $url = $c['base_url'].'/'.ltrim($endpoint, '/');

        $request = $this->http()->withToken($token);

        try {
            $response = $body !== null
                ? $request->withBody(json_encode($body, JSON_UNESCAPED_SLASHES), 'application/vnd.api+json')
                    ->send($method, $url)
                : $request->send($method, $url);
        } catch (ConnectionException $e) {
            $this->transportFailed = true;
            Log::error('Coinsbuy is unreachable.', [
                'mode' => $c['mode'],
                'endpoint' => $endpoint,
                'exception' => $e->getMessage(),
            ]);
            $this->trace('request.connection_failed', [
                'endpoint' => $endpoint,
                'method' => $method,
                'exception' => $e->getMessage(),
            ]);

            // status 0 is the caller's signal for "never got an answer", the
            // same shape a failed token fetch produces.
            return ['ok' => false, 'status' => 0, 'data' => []];
        }

        if ($response->status() === 401 && ! $retried) {
            $this->trace('request.retry_after_401', ['endpoint' => $endpoint]);

            return $this->request($endpoint, $method, $body, true);
        }

        if (! $response->successful()) {
            $detail = (string) $response->json('errors.0.detail');

            Log::error('Coinsbuy API call failed.', [
                'mode' => $c['mode'],
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'detail' => $detail,
            ]);
            $this->trace('request.error', [
                'endpoint' => $endpoint,
                'method' => $method,
                'status' => $response->status(),
                'code' => $response->json('errors.0.code'),
                'detail' => $detail,
            ]);

            // A second 401 is a credentials problem, not a stale token — say so
            // rather than letting it read as "no wallet" further down.
            if ($response->status() === 401) {
                $this->authFailed = true;
            }

            // Coinsbuy can restrict an account to allow-listed server IPs. It
            // reads as a plain 403, so name it — otherwise it looks like bad
            // credentials and the keys get regenerated for nothing.
            if ($response->status() === 403
                && ($response->json('errors.0.code') === '2016' || stripos($detail, 'white list') !== false)) {
                Log::error('Coinsbuy: this server\'s outbound IP is not allow-listed in the Coinsbuy dashboard.');
            }
        }

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'data' => (array) ($response->json() ?? []),
        ];
    }

    // ---- lookups ---------------------------------------------------------

    public function currencyId(string $alpha): ?string
    {
        $mode = $this->mode();

        return Cache::remember("coinsbuy:currency:{$mode}:".strtoupper($alpha), 86400, function () use ($alpha) {
            $res = $this->request('currency/?filter[alpha]='.urlencode(strtoupper($alpha)));
            if (! $res['ok']) {
                return null;
            }

            $data = $res['data']['data'] ?? null;
            if (isset($data['id'])) {
                return (string) $data['id'];
            }
            if (is_array($data) && isset($data[0]['id'])) {
                return (string) $data[0]['id'];
            }

            return null;
        });
    }

    /**
     * An Active wallet for a currency. Walks every page — the mother's loop
     * (`while ($page <= $totalPages && count($wallets) === $pageSize)`) stopped
     * early whenever a page came back short, hiding wallets on later pages.
     */
    public function walletIdForCurrency(string $code): ?string
    {
        $mode = $this->mode();
        $code = strtoupper($code);

        return Cache::remember("coinsbuy:wallet:{$mode}:{$code}", 600, function () use ($code) {
            $numericId = $this->currencyId($code);

            $page = 1;
            $totalPages = 1;
            $seen = 0;

            do {
                $res = $this->request("wallet/?page[number]={$page}&page[size]=50");
                if (! $res['ok']) {
                    return null;
                }

                $data = $res['data']['data'] ?? [];
                $wallets = isset($data['id']) ? [$data] : (is_array($data) ? $data : []);
                $totalPages = (int) ($res['data']['meta']['total_pages'] ?? 1);
                $seen += count($wallets);

                foreach ($wallets as $wallet) {
                    $walletCurrency = $wallet['relationships']['currency']['data']['id'] ?? null;
                    if ($walletCurrency === null) {
                        continue;
                    }

                    $matches = strtoupper((string) $walletCurrency) === $code
                        || ($numericId !== null && (string) $walletCurrency === $numericId);
                    if (! $matches) {
                        continue;
                    }

                    // Status 3 = Active, per the Coinsbuy Wallets docs.
                    $status = $wallet['attributes']['status'] ?? null;
                    $active = $status == 3 || strtolower((string) $status) === 'active';
                    if ($active) {
                        return (string) $wallet['id'];
                    }
                }

                $page++;
            } while ($page <= $totalPages);

            Log::warning('Coinsbuy: no active wallet found.', [
                'currency' => $code,
                'wallets_scanned' => $seen,
            ]);
            $this->trace('wallet.none_active', [
                'currency' => $code,
                'wallets_scanned' => $seen,
                'pages' => $totalPages,
            ]);

            return null;
        });
    }

    // ---- deposits --------------------------------------------------------

    /**
     * Create a deposit for an invoice and hand back where to send the trader.
     *
     * Merchant (fiat) wallet is the normal path — it yields a hosted payment
     * page. The Enterprise fallback yields a bare crypto address, and then
     * target_amount_requested / payment_page_* must be omitted.
     *
     * @return array{deposit_id: string, tracking_id: string, status: mixed, payment_type: string, payment_url: ?string, destination: ?string}
     *
     * @throws RuntimeException
     */
    public function createDeposit(Invoice $invoice, string $accountName, string $cryptocurrency): array
    {
        $conf = (array) config('payments.coinsbuy');
        $fiat = (string) ($conf['fiat_currency'] ?? 'USD');
        $amount = $invoice->feeCents() / 100;

        // Configured wallet id first, then a Merchant fiat wallet, then an
        // Enterprise wallet in the requested coin.
        $walletId = $this->env->coinsbuy()['wallet_id'] ?: null;
        $isMerchant = $walletId !== null;
        $walletSource = $isMerchant ? 'configured' : null;

        if ($walletId === null) {
            $walletId = $this->walletIdForCurrency($fiat);
            $isMerchant = $walletId !== null;
            $walletSource = $isMerchant ? "merchant:{$fiat}" : null;
        }
        if ($walletId === null) {
            $walletId = $this->walletIdForCurrency($cryptocurrency);
            $walletSource = $walletId !== null ? "enterprise:{$cryptocurrency}" : null;
        }
        if ($walletId === null) {
            $this->trace('wallet.unresolved', ['fiat' => $fiat, 'crypto' => $cryptocurrency]);

            throw new RuntimeException($this->failureCode('COINSBUY_NO_WALLET'));
        }

        $this->trace('wallet.resolved', [
            'wallet_id' => $walletId,
            'source' => $walletSource,
            'is_merchant' => $isMerchant,
        ]);

        $trackingId = self::trackingIdFor((int) $invoice->id);

        // Coinsbuy labels: max 32 chars, and special characters (dashes above
        // all) are rejected outright.
        $label = 'Invoice Payment '.preg_replace('/[^a-zA-Z0-9 _]/', '', $accountName);
        $label = mb_substr($label, 0, 32);

        $attributes = [
            'label' => $label,
            'tracking_id' => $trackingId,
            'confirmations_needed' => (int) ($conf['confirmations'] ?? 1),
            'callback_url' => $this->env->coinsbuyCallbackUrl(),
        ];

        if ($isMerchant) {
            $inaccuracyPct = (float) ($conf['inaccuracy_pct'] ?? 1.0);
            $attributes['target_amount_requested'] = number_format($amount, 2, '.', '');
            $attributes['inaccuracy'] = number_format($amount * $inaccuracyPct / 100, 2, '.', '');
            $attributes['time_limit'] = (int) ($conf['time_limit'] ?? 3600);
            $attributes['payment_page_redirect_url'] = $this->env->successUrl(
                (int) $invoice->id, 'transaction_id', $trackingId
            );
            $attributes['payment_page_button_text'] = 'Return to Dashboard';
        }

        $relationships = [
            'wallet' => ['data' => ['type' => 'wallet', 'id' => (string) $walletId]],
        ];

        // Pre-selects the coin on the hosted page.
        $currencyId = $this->currencyId($cryptocurrency) ?? $this->currencyId($this->defaultCryptocurrency());
        if ($currencyId !== null) {
            $relationships['currency'] = ['data' => ['type' => 'currency', 'id' => $currencyId]];
        }

        // Type is 'deposit' — singular — and the trailing slash matters.
        $res = $this->request('deposit/', 'POST', [
            'data' => [
                'type' => 'deposit',
                'attributes' => $attributes,
                'relationships' => $relationships,
            ],
        ]);

        if ($this->transportFailed || $res['status'] === 0) {
            throw new RuntimeException($this->failureCode('COINSBUY_UNREACHABLE'));
        }

        if (! $res['ok'] || ! isset($res['data']['data']['id'])) {
            $this->trace('deposit.rejected', [
                'status' => $res['status'],
                'currency_id' => $currencyId,
                'target_amount_requested' => $attributes['target_amount_requested'] ?? null,
                'label' => $label,
                // Present but shapeless when Coinsbuy 200s a body we cannot read.
                'body_keys' => array_keys($res['data']),
            ]);

            throw new RuntimeException($this->failureCode('COINSBUY_ERROR'));
        }

        $deposit = $res['data']['data'];
        $depositAttributes = $deposit['attributes'] ?? [];
        $paymentPage = $depositAttributes['payment_page'] ?? null;
        $destination = self::unwrapDestination($depositAttributes);

        return [
            'deposit_id' => (string) $deposit['id'],
            'tracking_id' => $trackingId,
            'status' => $depositAttributes['status'] ?? null,
            'payment_type' => $paymentPage ? 'payment_page' : 'destination_address',
            'payment_url' => $paymentPage ?: $destination,
            'destination' => $destination,
        ];
    }

    /** `destination` is a string, an object, or (for XRP) an array of objects. */
    public static function unwrapDestination(array $attributes): ?string
    {
        $destination = $attributes['destination'] ?? null;

        if (is_string($destination) && $destination !== '') {
            return $destination;
        }
        if (is_array($destination)) {
            if (isset($destination['address'])) {
                return (string) $destination['address'];
            }
            if (isset($destination[0]['address'])) {
                return (string) $destination[0]['address'];
            }
        }
        if (isset($attributes['address']) && is_string($attributes['address'])) {
            return $attributes['address'];
        }

        return null;
    }
}
