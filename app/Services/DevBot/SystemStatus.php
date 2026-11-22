<?php

namespace App\Services\DevBot;

use App\Models\RiskAlert;
use App\Models\TronPaymentClaim;
use App\Models\TronTransfer;
use App\Services\EngineCache;
use App\Services\Payments\PaymentEnvironment;
use App\Services\Payments\TronGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * "Are the services up?" — the error bot's /status (2026-11-22, Christian:
 * "especially invoicing, like the crypto listeners").
 *
 * Built HERE, on the server, from what each service already records about
 * itself — no exchange call, no Claude — so it answers in a second and works
 * when the PC is off. Each check is ok (true), a problem (false) or unknown
 * (null, e.g. never measured yet). `devbot:watch` reads the same checks and
 * DMs only when one changes, so the report and the alarms cannot disagree.
 */
class SystemStatus
{
    /** The scheduler writes this every minute (routes/console.php). */
    public const HEARTBEAT_KEY = 'scheduler:heartbeat';

    public const HEARTBEAT_STALE_SECONDS = 180;

    /** A queued job waiting longer than this = nothing is working the queue. */
    public const QUEUE_STUCK_MINUTES = 10;

    public const DISK_MIN_FREE_PCT = 10;

    public function __construct(private EngineCache $engine, private PaymentEnvironment $env, private DevBotJobs $jobs) {}

    /** @return list<array{key: string, name: string, ok: ?bool, detail: string}> */
    public function checks(): array
    {
        $out = [];
        foreach (['engine', 'scheduler', 'crypto', 'cards', 'invoicing', 'queue', 'alerts', 'disk', 'agent'] as $key) {
            try {
                $out[] = ['key' => $key] + $this->{$key}();
            } catch (Throwable $e) {
                $out[] = ['key' => $key, 'name' => ucfirst($key), 'ok' => null, 'detail' => 'could not be checked: '.mb_substr($e->getMessage(), 0, 120)];
            }
        }

        return $out;
    }

    /** The whole report, as the DM shows it. */
    public function report(): string
    {
        $checks = $this->checks();
        $bad = array_filter($checks, fn ($c) => $c['ok'] === false);
        $head = $bad === []
            ? '🟢 <b>All services up</b>'
            : '🔴 <b>'.count($bad).' problem'.(count($bad) === 1 ? '' : 's').'</b>';
        $lines = [$head, now('UTC')->format('j M H:i').' UTC', ''];
        foreach ($checks as $c) {
            $dot = $c['ok'] === true ? '🟢' : ($c['ok'] === false ? '🔴' : '⚪');
            $lines[] = "{$dot} <b>".e($c['name']).'</b> — '.e($c['detail']);
        }

        return implode("\n", $lines);
    }

    private function engine(): array
    {
        $h = $this->engine->health();
        if ($h === null) {
            return ['name' => 'Trading engine', 'ok' => false, 'detail' => 'not answering'];
        }
        $venues = array_keys((array) ($h['exchanges'] ?? []));
        $pollers = (array) ($h['pollers'] ?? []);
        $expected = 4 + (in_array('mexc', $venues, true) ? 1 : 0) + (in_array('bybit', $venues, true) ? 1 : 0);
        $retry = (int) ($h['retry_queue_depth'] ?? 0);
        $limited = $h['rate_limited_until'] ?? null;
        $ok = ($h['status'] ?? '') === 'ok' && count($pollers) >= $expected && ! $limited;
        $parts = [
            ($h['status'] ?? '?') === 'ok' ? 'running' : 'status '.($h['status'] ?? '?'),
            'trading '.(implode(', ', array_map(fn ($v) => \App\Services\Exchanges\ExchangeSchema::isSupported($v)
                ? \App\Services\Exchanges\ExchangeSchema::for($v)->brokerLabel : ucfirst($v), $venues)) ?: 'nothing'),
            count($pollers).'/'.$expected.' data pollers',
            "retry queue {$retry}",
        ];
        if ($limited) {
            $parts[] = 'RATE-LIMITED by the exchange until '.$limited;
        }

        return ['name' => 'Trading engine', 'ok' => $ok, 'detail' => implode(', ', $parts)];
    }

    private function scheduler(): array
    {
        $at = Cache::get(self::HEARTBEAT_KEY);
        if (! $at) {
            return ['name' => 'Scheduled jobs', 'ok' => null, 'detail' => 'no heartbeat recorded yet'];
        }
        $age = now()->timestamp - (int) $at;

        return ['name' => 'Scheduled jobs', 'ok' => $age <= self::HEARTBEAT_STALE_SECONDS,
            'detail' => $age <= self::HEARTBEAT_STALE_SECONDS ? 'running (last tick '.self::ago($age).')' : 'STOPPED — last tick '.self::ago($age)];
    }

    private function crypto(): array
    {
        $network = $this->env->tronNetworkFor(false);
        $last = TronGateway::lastScanAt($network);
        $stale = TronGateway::scanIsStale($network);
        $stalled = TronGateway::budgetExhaustedAt($network);
        $unmatched = TronTransfer::where('status', TronTransfer::STATUS_UNMATCHED)->where('network', $network)->count();
        $disputes = TronPaymentClaim::openDisputes()->where('network', $network)->distinct()->count('tron_transfer_id');
        $parts = [$last ? 'last scan '.self::ago(now()->timestamp - $last->timestamp) : 'never scanned'];
        if ($stalled) {
            $parts[] = 'STALLED (page budget used up)';
        }
        $parts[] = "{$unmatched} unmatched payment".($unmatched === 1 ? '' : 's');
        if ($disputes > 0) {
            $parts[] = "{$disputes} disputed";
        }

        return ['name' => "Crypto payment listener (USDT, {$network})", 'ok' => ! $stale && ! $stalled, 'detail' => implode(', ', $parts)];
    }

    private function cards(): array
    {
        $last = DB::table('payment_events')->where('provider', 'stripe')->max('created_at');

        return ['name' => 'Card payments (Stripe)', 'ok' => null,
            'detail' => $last ? 'last webhook '.Carbon::parse($last)->format('j M H:i').' UTC (it only hears from Stripe when someone pays)' : 'no webhook received yet'];
    }

    private function invoicing(): array
    {
        $h = $this->engine->health();
        $next = $h['monthly_invoices']['next'] ?? null;
        $enabled = $h['monthly_invoices']['enabled'] ?? null;
        $pending = DB::table('invoices')->where('status', 'pending')->where('total_fee', '>', 0)->count();
        $overdue = DB::table('invoices')->whereIn('status', ['overdue', 'failed'])->where('total_fee', '>', 0)->count();
        $parts = [
            $enabled === false ? 'monthly run SWITCHED OFF' : ($next ? 'next monthly run '.Carbon::parse($next)->format('j M H:i T') : 'next run unknown (engine silent)'),
            "{$pending} unpaid",
            "{$overdue} overdue",
        ];

        return ['name' => 'Invoicing', 'ok' => $h === null ? null : $enabled !== false, 'detail' => implode(', ', $parts)];
    }

    private function queue(): array
    {
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $oldest = Schema::hasTable('jobs') ? DB::table('jobs')->min('created_at') : null;
        $waiting = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $stuck = $oldest !== null && (int) $oldest < now()->subMinutes(self::QUEUE_STUCK_MINUTES)->timestamp;
        $parts = ["{$waiting} waiting", "{$failed} failed"];
        if ($stuck) {
            $parts[] = 'the oldest has waited over '.self::QUEUE_STUCK_MINUTES.' min';
        }

        return ['name' => 'Background jobs', 'ok' => ! $stuck && $failed === 0, 'detail' => implode(', ', $parts)];
    }

    private function alerts(): array
    {
        $open = RiskAlert::open()->count();

        return ['name' => 'High-risk alerts', 'ok' => $open === 0, 'detail' => $open === 0 ? 'none open' : "{$open} open on Overview → Needs attention"];
    }

    private function disk(): array
    {
        if (! config('services.dev_bot.check_disk', true)) {
            return ['name' => 'Disk', 'ok' => null, 'detail' => 'not checked here'];
        }
        $free = @disk_free_space('/') ?: @disk_free_space(base_path());
        $total = @disk_total_space('/') ?: @disk_total_space(base_path());
        if (! $free || ! $total) {
            return ['name' => 'Disk', 'ok' => null, 'detail' => 'unreadable'];
        }
        $pct = round($free / $total * 100);

        return ['name' => 'Disk', 'ok' => $pct >= self::DISK_MIN_FREE_PCT, 'detail' => round($free / 1e9, 1)." GB free ({$pct}%)"];
    }

    private function agent(): array
    {
        return ['name' => 'PC developer agent', 'ok' => null,
            'detail' => $this->jobs->agentOnline() ? 'online' : 'offline (only needed for Investigate and Deploy)'];
    }

    public static function ago(int $seconds): string
    {
        return match (true) {
            $seconds < 90 => max(0, $seconds).' s ago',
            $seconds < 5400 => round($seconds / 60).' min ago',
            $seconds < 172800 => round($seconds / 3600).' h ago',
            default => round($seconds / 86400).' days ago',
        };
    }
}
