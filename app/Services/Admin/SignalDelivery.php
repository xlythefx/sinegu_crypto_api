<?php

namespace App\Services\Admin;

use App\Models\TradeLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * How a signal got from TradingView to the engine — the Signal Log's
 * "delivery proof".
 *
 * TradingView gives up on a webhook after about 3 seconds and marks the alert
 * "failed — request took too long and timed out", even when the request is
 * still on its way and arrives a few seconds later. On 24 Oct 2026 the 14:15
 * UTC LTC entry did exactly that: TradingView said timed out, the engine
 * received it 8 s after it fired, answered at once and filled five accounts.
 * This class turns the engine's timing into the line that proves it.
 *
 * SLOW is a hint, never a verdict: TradingView's alert time is when the alert
 * FIRED, and it sometimes queues an alert for several seconds before sending
 * it (the 14:30 alert that day reached us 10 s after firing and still read
 * "delivered"). So a slow row MAY be shown as failed on TradingView; a fast
 * one will not be.
 */
class SignalDelivery
{
    /** TradingView's webhook timeout. */
    public const SLOW_MS = 3000;

    /**
     * The timing columns from an engine payload. Lenient on purpose: a missing
     * or malformed value is NULL, never a 422, because a timing field must not
     * cost the signal its Signal Log row.
     *
     * @return array{sent_at: ?CarbonImmutable, received_at: ?CarbonImmutable, transit_ms: ?int, ack_ms: ?float}
     */
    public static function fromPayload(mixed $sentAt, mixed $receivedAt, mixed $ackMs): array
    {
        $sent = self::parse($sentAt);
        $received = self::parse($receivedAt);
        $ack = is_numeric($ackMs) && (float) $ackMs >= 0 && (float) $ackMs < 3_600_000
            ? round((float) $ackMs, 2)
            : null;

        // A day either way is not transit, it is a wrong clock or a stale field.
        $transit = null;
        if ($sent && $received) {
            $ms = (int) round($received->getPreciseTimestamp(3) - $sent->getPreciseTimestamp(3));
            $transit = abs($ms) <= 86_400_000 ? $ms : null;
        }

        return ['sent_at' => $sent, 'received_at' => $received, 'transit_ms' => $transit, 'ack_ms' => $ack];
    }

    /** The row's `delivery` block, or null when the engine recorded no timing. */
    public static function present(TradeLog $log): ?array
    {
        if ($log->received_at === null) {
            return null;
        }
        $transit = $log->transit_ms !== null ? (int) $log->transit_ms : null;
        $ack = $log->ack_ms !== null ? (float) $log->ack_ms : null;

        return [
            'sent_at' => optional($log->sent_at)->toIso8601String(),
            'received_at' => $log->received_at->toIso8601String(),
            'transit_ms' => $transit,
            'ack_ms' => $ack,
            'slow' => self::isSlow($transit, $ack),
        ];
    }

    public static function isSlow(?int $transitMs, ?float $ackMs): bool
    {
        return ($transitMs ?? 0) + ($ackMs ?? 0) > self::SLOW_MS;
    }

    /** `?delivery=slow` — the same rule as isSlow(), in SQL. */
    public static function whereSlow(Builder $query): Builder
    {
        return $query->whereNotNull('received_at')
            ->whereRaw('(COALESCE(transit_ms, 0) + COALESCE(ack_ms, 0)) > ?', [self::SLOW_MS]);
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return is_numeric($value)
                ? CarbonImmutable::createFromTimestampMs((float) $value, 'UTC')
                : CarbonImmutable::parse((string) $value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
