<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TronGrid, read-only. Mirrors CoinsbuyGateway's shape — one http() builder for
 * every transport knob, one request() that never throws, a bounded trace, and a
 * diagnostics() that exposes presence booleans rather than values.
 *
 * The difference worth stating: this gateway only ever READS PUBLIC DATA. There
 * is no token to fetch, no credential to be refused, and no state on the
 * provider side. The optional TRON-PRO-API-KEY buys rate limit, not access, so
 * an unset key degrades to the anonymous tier instead of failing.
 */
class TronGateway
{
    private const TRACE_LIMIT = 40;

    /** Cache key holding the last time a network was successfully scanned. */
    private const SCAN_STAMP = 'tron:last_scan:';

    /** True once a call died before TronGrid answered at all. */
    private bool $transportFailed = false;

    /** @var list<array<string, mixed>> */
    private array $trace = [];

    public function __construct(private PaymentEnvironment $env) {}

    private function trace(string $step, array $detail = []): void
    {
        if (count($this->trace) >= self::TRACE_LIMIT) {
            return;
        }

        $this->trace[] = ['step' => $step] + $detail;
    }

    public function isConfigured(string $network): bool
    {
        return $this->env->tron($network)['configured'];
    }

    /**
     * Why this network is or is not working, for `developer` accounts and the
     * admin screen. The API key appears as a boolean and never as a value; the
     * addresses are public by construction, so they may be shown.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(string $network): array
    {
        $t = $this->env->tron($network);
        $scannedAt = self::lastScanAt($network);

        return [
            'network' => $network,
            'base_url' => $t['base_url'],
            'api_key_set' => $t['api_key'] !== '',
            'address' => $t['address'] !== '' ? $t['address'] : null,
            'address_valid' => $t['address_valid'],
            'contract' => $t['contract'] !== '' ? $t['contract'] : null,
            'contract_valid' => $t['contract_valid'],
            'decimals' => $t['decimals'],
            'configured' => $t['configured'],
            'last_scan_at' => $scannedAt?->toIso8601String(),
            'scan_stale' => self::scanIsStale($network),
            'transport_failed' => $this->transportFailed,
            'trace' => $this->trace,
        ];
    }

    // ---- scan freshness --------------------------------------------------

    /**
     * When this network was last scanned successfully.
     *
     * Kept in the cache rather than a column because it is a liveness signal,
     * not a record: losing it to a cache flush costs one minute of a red banner
     * and nothing else. It matters because scheduling the watcher makes the
     * cron entry LOAD-BEARING FOR MONEY — a dead scheduler used to cost a daily
     * overdue sweep, and now it silently stops invoices settling. The admin
     * screen and the trader's pay sheet both read this so a stalled watcher
     * says so instead of spinning forever.
     */
    public static function lastScanAt(string $network): ?Carbon
    {
        $stamp = Cache::get(self::SCAN_STAMP.$network);

        return $stamp ? Carbon::parse($stamp) : null;
    }

    public static function markScanned(string $network): void
    {
        Cache::put(self::SCAN_STAMP.$network, now()->toIso8601String(), now()->addDay());
    }

    public static function scanIsStale(string $network): bool
    {
        $at = self::lastScanAt($network);
        if ($at === null) {
            return true;
        }

        $minutes = (int) config('payments.tron.scan_stale_minutes', 10);

        return $at->lt(now()->subMinutes($minutes));
    }

    // ---- transport -------------------------------------------------------

    /** One place for every timeout and TLS knob. Same rationale as Coinsbuy's. */
    private function http(string $network): PendingRequest
    {
        $conf = (array) config('payments.tron');
        $t = $this->env->tron($network);

        $headers = ['Accept' => 'application/json'];
        if ($t['api_key'] !== '') {
            $headers['TRON-PRO-API-KEY'] = $t['api_key'];
        }

        $request = Http::timeout((int) ($conf['timeout'] ?? 20))
            ->connectTimeout((int) ($conf['connect_timeout'] ?? 8))
            ->withHeaders($headers);

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

    /**
     * One GET. Never throws: a status of 0 means TronGrid was never reached,
     * which the caller must distinguish from a 4xx it actually answered with.
     *
     * @return array{ok: bool, status: int, data: array}
     */
    public function request(string $network, string $path, array $query = []): array
    {
        $t = $this->env->tron($network);
        $url = $t['base_url'].$path;

        try {
            $response = $this->http($network)->get($url, $query);
        } catch (ConnectionException $e) {
            $this->transportFailed = true;
            Log::error('TronGrid is unreachable.', [
                'network' => $network,
                'url' => $url,
                'exception' => $e->getMessage(),
            ]);
            $this->trace('request.connection_failed', [
                'url' => $url,
                'exception' => $e->getMessage(),
            ]);

            return ['ok' => false, 'status' => 0, 'data' => []];
        }

        $data = (array) $response->json();

        if (! $response->successful()) {
            $this->trace('request.rejected', [
                'url' => $url,
                'status' => $response->status(),
                // TronGrid's own error text, never a cURL string.
                'error' => $data['Error'] ?? ($data['error'] ?? null),
            ]);
        }

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'data' => $data,
        ];
    }

    // ---- reads -----------------------------------------------------------

    /**
     * Every incoming TRC-20 transfer at or after $sinceMs, oldest first.
     *
     * `order_by=block_timestamp,asc` IS LOAD-BEARING AND MUST NOT BE REMOVED.
     * TronGrid defaults to descending. With descending order plus a page limit,
     * a burst larger than one page (trivial to cause — the address is public and
     * anyone can spray dust at it for free) returns the NEWEST 200, the caller
     * advances its cursor to the newest timestamp it saw, and every older
     * transfer it never fetched is skipped permanently. Including a real
     * payment. Ascending order makes an exhausted page budget harmless: the next
     * run simply resumes where this one stopped.
     *
     * `only_confirmed=true` is the finality rule. TRON's own integration
     * guidance is to read solidified blocks, which lag the head by about a
     * minute and cannot be rolled back by a fork — so there is no confirmation
     * count to tune here, and inventing one would only add lag.
     *
     * @return array{ok: bool, status: int, items: list<array>, pages: int, truncated: bool}
     */
    public function incomingTransfers(string $network, int $sinceMs, ?int $maxPages = null): array
    {
        $conf = (array) config('payments.tron');
        $t = $this->env->tron($network);

        $limit = (int) ($conf['page_limit'] ?? 200);
        $maxPages = $maxPages ?? (int) ($conf['max_pages'] ?? 10);

        $items = [];
        $pages = 0;
        $fingerprint = null;
        $truncated = false;

        while ($pages < $maxPages) {
            $query = [
                'only_to' => 'true',
                'only_confirmed' => 'true',
                'order_by' => 'block_timestamp,asc',
                'min_timestamp' => $sinceMs,
                'limit' => $limit,
            ];
            if ($fingerprint !== null) {
                $query['fingerprint'] = $fingerprint;
            }

            $result = $this->request(
                $network,
                '/v1/accounts/'.$t['address'].'/transactions/trc20',
                $query
            );

            if (! $result['ok']) {
                // Partial pages are still worth returning — they are already
                // deduped downstream, and the caller declines to advance its
                // cursor on a failed scan.
                return [
                    'ok' => false,
                    'status' => $result['status'],
                    'items' => $items,
                    'pages' => $pages,
                    'truncated' => true,
                ];
            }

            $page = (array) ($result['data']['data'] ?? []);
            $pages++;

            foreach ($page as $item) {
                $items[] = (array) $item;
            }

            $this->trace('page.read', ['page' => $pages, 'count' => count($page)]);

            $fingerprint = $result['data']['meta']['fingerprint'] ?? null;

            // No fingerprint, or a short page, means we have caught up.
            if ($fingerprint === null || count($page) < $limit) {
                break;
            }

            if ($pages >= $maxPages) {
                $truncated = true;
            }
        }

        return [
            'ok' => true,
            'status' => 200,
            'items' => $items,
            'pages' => $pages,
            'truncated' => $truncated,
        ];
    }

    /**
     * Has the receiving address ever been seen on chain? A brand-new wallet
     * answers false, which is normal — but paired with `address_valid` it tells
     * an operator whether they are looking at an unused wallet or a typo.
     */
    public function accountExists(string $network): bool
    {
        $t = $this->env->tron($network);
        if (! $t['address_valid']) {
            return false;
        }

        $result = $this->request($network, '/v1/accounts/'.$t['address'], []);

        return $result['ok'] && ! empty($result['data']['data']);
    }
}
