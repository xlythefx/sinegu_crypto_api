<?php

namespace App\Services\Alerts;

use App\Http\Controllers\AdminInsightsController;
use App\Mail\RiskAlertNotice;
use App\Models\RiskAlert;
use App\Services\DevBot\DevBotReporter;
use App\Services\EngineCache;
use App\Services\Notifications\TradeAlerts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * High-risk alerts (2026-11-18, the user's request): the serious failures
 * that need a person, told the same way whatever raised them.
 *
 *  - **Recorded** as a `risk_alerts` row, shown on Admin → Overview → Needs
 *    attention until a full admin marks it resolved.
 *  - **Emailed** to the failed-trade list (TRADE_ALERT_EMAILS,
 *    TradeAlerts::recipients()), never the team list.
 *  - **Telegram** (admin group, through the engine) only where the engine has
 *    not already said it: a feed breach is found HERE, everything else the
 *    engine already posted.
 *
 * One problem, one row: raising the same kind + key while its row is still
 * open counts it again, and re-emails at most once an hour
 * (EMAIL_EVERY_MINUTES), so a problem that repeats every signal cannot flood
 * the inbox. Best-effort throughout — a mail or Telegram failure is logged
 * and never fails the request that found the problem.
 */
class RiskAlerts
{
    public const EMAIL_EVERY_MINUTES = 60;

    /** The plain-English name of each kind (email kicker, Overview label). */
    public const LABELS = [
        RiskAlert::FEED_BREACH => 'Trade reached the wrong accounts',
        RiskAlert::EXIT_STUCK => 'A close failed',
        RiskAlert::KILL_SWITCH => 'Kill switch tripped',
        RiskAlert::KEY_REFUSED => 'House account key refused',
        RiskAlert::ENGINE_DOWN => 'Bot engine not answering',
        RiskAlert::ASSETS_UNAVAILABLE => 'Signal refused: coin list unavailable',
    ];

    public function __construct(private EngineCache $engine)
    {
    }

    /**
     * @param  list<string>  $lines  plain sentences, no secrets
     * @param  bool  $email  false when the caller already mailed the same list
     * @param  bool  $telegram  true only for what the engine has not posted
     */
    public function raise(
        string $kind,
        string $dedupeKey,
        string $title,
        array $lines = [],
        ?string $exchange = null,
        ?int $tradeLogId = null,
        bool $email = true,
        bool $telegram = false,
    ): ?RiskAlert {
        try {
            $now = now();
            $alert = RiskAlert::open()->where('kind', $kind)->where('dedupe_key', $dedupeKey)
                ->orderByDesc('id')->first();
            if ($alert) {
                $alert->occurrences++;
                $alert->last_seen_at = $now;
                $alert->title = $title;
                $alert->lines = $lines;
                $alert->trade_log_id = $tradeLogId ?? $alert->trade_log_id;
                $alert->save();
                $fresh = false;
            } else {
                $alert = RiskAlert::create([
                    'kind' => $kind,
                    'dedupe_key' => mb_substr($dedupeKey, 0, 191),
                    'exchange' => $exchange,
                    'title' => mb_substr($title, 0, 255),
                    'lines' => $lines,
                    'trade_log_id' => $tradeLogId,
                    'occurrences' => 1,
                    'last_seen_at' => $now,
                ]);
                $fresh = true;
            }
            Cache::forget(AdminInsightsController::CACHE_PREFIX.'overview');
        } catch (Throwable $e) {
            Log::error('RiskAlerts: could not record an alert.', ['kind' => $kind, 'exception' => $e->getMessage()]);

            return null;
        }

        $due = $fresh || $alert->emailed_at === null
            || $alert->emailed_at->lt(now()->subMinutes(self::EMAIL_EVERY_MINUTES));
        if ($email && $due && $this->mail($alert)) {
            $alert->forceFill(['emailed_at' => now()])->save();
        }
        if ($telegram && $fresh && $this->engine->riskAlert(self::LABELS[$kind] ?? $kind, $title, $lines)) {
            $alert->forceFill(['telegram_at' => now()])->save();
        }
        // The developer error bot hears every NEW alert, whoever raised it
        // (repeats only count, like the email's hourly limit). Best-effort.
        if ($fresh) {
            app(DevBotReporter::class)->riskAlert($alert);
        }

        return $alert;
    }

    /** Close every open alert of a kind + key by itself (the engine answered again). */
    public function recover(string $kind, string $dedupeKey, string $note): int
    {
        $n = RiskAlert::open()->where('kind', $kind)->where('dedupe_key', $dedupeKey)
            ->update(['resolved_at' => now(), 'resolved_by_name' => 'System', 'resolve_note' => $note]);
        if ($n > 0) {
            Cache::forget(AdminInsightsController::CACHE_PREFIX.'overview');
        }

        return $n;
    }

    private function mail(RiskAlert $alert): bool
    {
        $to = TradeAlerts::recipients();
        if ($to === []) {
            return false;
        }
        try {
            Mail::to($to)->send(new RiskAlertNotice(
                kind: $alert->kind,
                label: self::LABELS[$alert->kind] ?? $alert->kind,
                title: $alert->title,
                lines: array_values(array_map('strval', $alert->lines ?? [])),
                occurrences: $alert->occurrences,
                at: $alert->last_seen_at->copy()->utc()->format('j M Y, H:i').' UTC',
            ));

            return true;
        } catch (Throwable $e) {
            Log::warning('RiskAlerts: email could not be sent.', ['kind' => $alert->kind, 'exception' => $e->getMessage()]);

            return false;
        }
    }

    /** @return array<string, mixed> one row for the Overview */
    public static function present(RiskAlert $a): array
    {
        return [
            'id' => $a->id,
            'kind' => $a->kind,
            'label' => self::LABELS[$a->kind] ?? $a->kind,
            'title' => $a->title,
            'lines' => $a->lines ?? [],
            'exchange' => $a->exchange,
            'trade_log_id' => $a->trade_log_id,
            'occurrences' => $a->occurrences,
            'first_seen_at' => $a->created_at?->toIso8601String(),
            'last_seen_at' => $a->last_seen_at?->toIso8601String(),
            'emailed' => $a->emailed_at !== null,
        ];
    }
}
