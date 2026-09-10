<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Log;

/**
 * Decides — from the machine actually handling the request — whether payments
 * run against production or sandbox credentials, and builds every URL the
 * providers see.
 *
 * Why machine-based rather than APP_ENV: an .env copied from one server to
 * another carries its environment with it. The mother project hit exactly that
 * (a baked-in override made staging authenticate against the prod API), and its
 * env.php grew an IP/host resolver in response. This is that idea, with the key
 * selection and the URL selection coming from the SAME branch so they can never
 * disagree.
 *
 * Resolution order:
 *   1. config('payments.force')            explicit override (tests, forcing a box)
 *   2. SERVER_ADDR prefix-matches          the production IP list
 *   3. request host contains               a production host entry
 *   4. gethostname() matches               a production hostname (the CLI path —
 *      SERVER_ADDR does not exist under `php artisan`, and both the scheduler
 *      and `deploy-api`'s config:cache run there)
 *   5. otherwise                           sandbox
 *
 * Then one further rule, which is what makes today's answer correct and
 * tomorrow's automatic: LIVE KEYS REQUIRE HTTPS CALLBACKS. A production verdict
 * whose callback base URL is still plaintext resolves to the test key set.
 */
class PaymentEnvironment
{
    public const PRODUCTION = 'production';

    public const SANDBOX = 'sandbox';

    private ?string $name = null;

    private ?string $reason = null;

    /**
     * Per-request override that pins BOTH providers to their test credentials,
     * whatever the machine says. Set for `developer` accounts so the pay buttons
     * can be exercised against production without money moving.
     *
     * Deliberately one-way: nothing can force live keys on, only off.
     */
    private bool $forcedSandbox = false;

    /**
     * @param  array{server_addr?: string, host?: string, hostname?: string}  $signals
     *   Overrides for the machine signals. Tests inject these; in normal
     *   operation they are read from the request/OS.
     */
    public function __construct(private array $signals = []) {}

    // ---- verdict ---------------------------------------------------------

    public function name(): string
    {
        $this->resolve();

        return $this->name;
    }

    public function isProduction(): bool
    {
        return $this->name() === self::PRODUCTION;
    }

    /**
     * Pin this caller's provider credentials to the test key sets.
     *
     * URLs are untouched on purpose: a forced-sandbox payment made on the live
     * box must still redirect back to the live frontend and call back to the
     * live API. Only the keys change.
     *
     * Takes an argument rather than being one-way because this object is a
     * container singleton: under Octane, or in a test process, one developer's
     * request would otherwise pin test keys for every request after it. Callers
     * state the current caller's status; `false` only ever restores the machine
     * verdict, so this can never upgrade anyone to live keys.
     */
    public function forceSandbox(bool $on = true): void
    {
        $this->forcedSandbox = $on;
    }

    public function sandboxIsForced(): bool
    {
        return $this->forcedSandbox;
    }

    /** Why we landed on this environment — surfaced by GET /payments/methods. */
    public function reason(): string
    {
        $this->resolve();

        return $this->reason;
    }

    private function resolve(): void
    {
        if ($this->name !== null) {
            return;
        }

        $forced = strtolower((string) config('payments.force'));
        if (in_array($forced, [self::PRODUCTION, self::SANDBOX], true)) {
            $this->name = $forced;
            $this->reason = 'override';

            return;
        }

        $prod = (array) config('payments.environments.'.self::PRODUCTION, []);

        $serverAddr = $this->signal('server_addr', fn () => (string) ($_SERVER['SERVER_ADDR'] ?? ''));
        foreach ((array) ($prod['server_ips'] ?? []) as $ip) {
            if ($ip !== '' && $serverAddr !== '' && str_starts_with($serverAddr, $ip)) {
                $this->name = self::PRODUCTION;
                $this->reason = "server_ip:{$ip}";

                return;
            }
        }

        $host = strtolower($this->signal('host', function () {
            $raw = (string) ($_SERVER['HTTP_HOST'] ?? '');

            return explode(':', $raw)[0];   // strip the port
        }));
        foreach ((array) ($prod['hosts'] ?? []) as $needle) {
            if ($needle !== '' && $host !== '' && str_contains($host, strtolower($needle))) {
                $this->name = self::PRODUCTION;
                $this->reason = "host:{$needle}";

                return;
            }
        }

        $hostname = strtolower($this->signal('hostname', fn () => (string) gethostname()));
        foreach ((array) ($prod['hostnames'] ?? []) as $needle) {
            if ($needle !== '' && $hostname !== '' && $hostname === strtolower($needle)) {
                $this->name = self::PRODUCTION;
                $this->reason = "hostname:{$needle}";

                return;
            }
        }

        $this->name = self::SANDBOX;
        $this->reason = 'default';
    }

    /** @param  callable(): string  $default */
    private function signal(string $key, callable $default): string
    {
        return array_key_exists($key, $this->signals)
            ? (string) $this->signals[$key]
            : $default();
    }

    // ---- URLs ------------------------------------------------------------

    private function conf(string $key, $default = null)
    {
        return config("payments.environments.{$this->name()}.{$key}", $default);
    }

    /** Base for success/cancel links the browser is sent to. */
    public function frontendBaseUrl(): string
    {
        return rtrim((string) $this->conf('frontend_url', 'http://localhost:5173'), '/');
    }

    /** Base the browser talks to. */
    public function apiBaseUrl(): string
    {
        return rtrim((string) $this->conf('api_url', 'http://127.0.0.1:8000/api'), '/');
    }

    /**
     * Base the PROVIDERS talk to. Different from apiBaseUrl() in local dev,
     * where the browser reaches 127.0.0.1 but Stripe/Coinsbuy need a tunnel.
     */
    public function publicApiBaseUrl(): string
    {
        $public = (string) $this->conf('public_api_url', '');

        return $public !== '' ? rtrim($public, '/') : $this->apiBaseUrl();
    }

    public function callbacksAreSecure(): bool
    {
        return str_starts_with($this->publicApiBaseUrl(), 'https://');
    }

    /**
     * Where the gateway sends the trader back. `$value` may contain a provider
     * placeholder (Stripe's {CHECKOUT_SESSION_ID}), which must survive verbatim.
     */
    public function successUrl(int $invoiceId, string $param, string $value): string
    {
        return $this->frontendBaseUrl()
            ."/dashboard/invoices/{$invoiceId}?payment=success&{$param}={$value}";
    }

    public function cancelUrl(int $invoiceId): string
    {
        return $this->frontendBaseUrl()."/dashboard/invoices/{$invoiceId}?payment=cancelled";
    }

    public function stripeWebhookUrl(): string
    {
        return $this->publicApiBaseUrl().'/payments/stripe/webhook';
    }

    public function coinsbuyCallbackUrl(): string
    {
        return $this->publicApiBaseUrl().'/payments/coinsbuy/webhook';
    }

    // ---- credentials -----------------------------------------------------

    /**
     * Stripe key set for this machine.
     *
     * @return array{mode: string, secret: string, webhook_secret: string, downgraded: bool}
     */
    public function stripe(): array
    {
        $live = $this->isProduction() && ! $this->forcedSandbox;
        $downgraded = false;

        if ($live && config('payments.stripe.require_https_in_live') && ! $this->callbacksAreSecure()) {
            $live = false;
            $downgraded = true;
            Log::warning('Stripe stayed on TEST keys: live mode needs an https callback URL.', [
                'environment' => $this->name(),
                'reason' => $this->reason(),
                'callback_url' => $this->stripeWebhookUrl(),
            ]);
        }

        $mode = $live ? 'live' : 'test';

        return [
            'mode' => $mode,
            'secret' => (string) config("payments.stripe.{$mode}.secret", ''),
            'webhook_secret' => (string) config("payments.stripe.{$mode}.webhook_secret", ''),
            'downgraded' => $downgraded,
        ];
    }

    /**
     * Coinsbuy credentials for this machine. Held to the same HTTPS rule as
     * Stripe so both rails share one environment rather than drifting apart.
     *
     * @return array{mode: string, base_url: string, client_id: string, client_secret: string, webhook_secret: string, wallet_id: string, default_crypto: string, downgraded: bool}
     */
    public function coinsbuy(): array
    {
        $live = $this->isProduction() && ! $this->forcedSandbox;
        $downgraded = false;

        if ($live && config('payments.stripe.require_https_in_live') && ! $this->callbacksAreSecure()) {
            $live = false;
            $downgraded = true;
        }

        $mode = $live ? self::PRODUCTION : self::SANDBOX;
        $conf = (array) config("payments.coinsbuy.{$mode}", []);

        return [
            'mode' => $mode,
            'base_url' => rtrim((string) ($conf['base_url'] ?? ''), '/'),
            'client_id' => (string) ($conf['client_id'] ?? ''),
            'client_secret' => (string) ($conf['client_secret'] ?? ''),
            'webhook_secret' => (string) ($conf['webhook_secret'] ?? ''),
            'wallet_id' => (string) ($conf['wallet_id'] ?? ''),
            'default_crypto' => (string) ($conf['default_crypto'] ?? 'USDT'),
            'downgraded' => $downgraded,
        ];
    }

    // ---- TRON ------------------------------------------------------------

    /**
     * TRON settings for ONE named network.
     *
     * DELIBERATELY UNLIKE stripe() AND coinsbuy(), which take no argument and
     * answer for the machine. Those rails have one credential set per box, so
     * "which keys does this machine use" is a complete question. TRON has no
     * credentials at all — the "credential" is a public address on a public
     * chain — and the component that detects payments is a scheduled command
     * with no request and no user which must be able to scan EVERY configured
     * network from one process. So the network is an explicit argument, and
     * this method never consults $this->forcedSandbox: it must behave
     * identically under HTTP and under `php artisan`.
     *
     * The role-aware question ("which network does THIS caller get?") is asked
     * exactly once, in tronNetworkFor(), and its answer is written onto the
     * intent row so the watcher never has to ask it at all.
     *
     * @return array{network: string, base_url: string, api_key: string, address: string,
     *   contract: string, asset: string, decimals: int, label: string,
     *   explorer_tx: string, explorer_address: string,
     *   configured: bool, address_valid: bool, contract_valid: bool}
     */
    public function tron(string $network): array
    {
        $conf = (array) config("payments.tron.networks.{$network}", []);

        $address = (string) ($conf['address'] ?? '');
        $contract = (string) ($conf['contract'] ?? '');
        $baseUrl = rtrim((string) ($conf['base_url'] ?? ''), '/');

        // Checksums, not patterns — a mutated character in either of these
        // sends money somewhere unrecoverable, silently. See TronAddress.
        $addressValid = TronAddress::isValid($address);
        $contractValid = TronAddress::isValid($contract);

        return [
            'network' => $network,
            'base_url' => $baseUrl,
            'api_key' => (string) ($conf['api_key'] ?? ''),
            'address' => $address,
            'contract' => $contract,
            'asset' => (string) ($conf['asset'] ?? 'USDT'),
            'decimals' => (int) ($conf['decimals'] ?? 6),
            'label' => (string) ($conf['label'] ?? 'TRC-20 (TRON)'),
            'explorer_tx' => (string) ($conf['explorer_tx'] ?? ''),
            'explorer_address' => (string) ($conf['explorer_address'] ?? ''),
            'configured' => $baseUrl !== '' && $addressValid && $contractValid,
            'address_valid' => $addressValid,
            'contract_valid' => $contractValid,
        ];
    }

    /**
     * Which network this caller's intent belongs on — the only role-aware call
     * in the whole TRON path.
     *
     * Developers get the test network on EVERY machine, production included:
     * the counterpart of forceSandbox() pinning them to sandbox provider keys,
     * and what makes the rail exercisable end to end on the live box with no
     * money moving. Everyone else is gated on the machine verdict rather than a
     * single flat default, so a non-developer on a dev box is never handed the
     * real mainnet receiving address.
     */
    public function tronNetworkFor(bool $isDeveloper): string
    {
        if ($isDeveloper) {
            return (string) config('payments.tron.developer_network', 'nile');
        }

        return (string) config(
            "payments.tron.default_network.{$this->name()}",
            self::SANDBOX
        );
    }

    /**
     * Every network worth scanning — the watcher's input. A network with no
     * base URL, no receiving address or a checksum-invalid one is skipped
     * rather than polled into the void.
     *
     * Note that this returns Nile on the production box too. Watching a test
     * network costs one HTTP call a minute and is exactly what lets a developer
     * rehearse on prod.
     *
     * @return list<string>
     */
    public function tronNetworks(): array
    {
        $names = array_keys((array) config('payments.tron.networks', []));

        return array_values(array_filter(
            $names,
            fn ($network) => $this->tron((string) $network)['configured']
        ));
    }

    /** Is the TRON rail offered to non-developers yet? */
    public function tronIsPublic(): bool
    {
        return (bool) config('payments.tron.public', false);
    }

    /**
     * May a payment on this network be SIMULATED — settled by a developer
     * without any money moving?
     *
     * The rule is defined against the network that carries real money rather
     * than by name, so renaming a network in config cannot open the door: if
     * `default_network.production` points at it, it is never simulatable, on any
     * box, for any role. Combined with the `developer` middleware on the route,
     * forging revenue takes two independent failures rather than one.
     *
     * Note this is deliberately NOT "are we on a dev machine" — a developer
     * rehearsing on the production box is the whole point of the role, and they
     * are on the test network there too.
     */
    public function tronIsSimulatable(string $network): bool
    {
        $live = (string) config('payments.tron.default_network.'.self::PRODUCTION, 'mainnet');

        return $network !== '' && $network !== $live;
    }
}
