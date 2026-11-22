<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Admin → Error Bot's switches — one row (App\Services\DevBot). What the bot
 * may send, a mute-everything-until, and the PC agent's pause.
 *
 * Replies to something Christian asked for (a job's progress and answer, a
 * button's result, /status) are never muted: muting is for the bot speaking
 * first.
 */
class DevBotSetting extends Model
{
    /** key => [label, what it covers] — the kinds the page can switch off. */
    public const KINDS = [
        'signals' => ['Trade problems', 'A signal an account could not trade: an order refused, a key blocked, a stack unreadable.'],
        'coins' => ['Missing-coin buttons', 'A signal for a coin that is not set up, with $5 / $10 / $15 size buttons. Off = a plain trade-problem message instead.'],
        'risk' => ['High-risk alerts', 'Kill switch, a close that failed, a house key refused, the engine not answering, a trade on the wrong feed.'],
        'errors' => ['Server errors', 'Any crash on the API: in a page request, a command or a scheduled job.'],
        'scheduled' => ['Failed scheduled jobs', 'A scheduled job (crypto listener, overdue sweep, …) that ended with a failure code.'],
        'watchdog' => ['Service down / recovered', 'The crypto payment listener, the scheduler, background jobs, engine pollers or disk stopping, and coming back.'],
    ];

    protected $table = 'dev_bot_settings';

    protected $fillable = ['disabled_kinds', 'muted_until', 'agent_paused', 'updated_by_name'];

    protected function casts(): array
    {
        return [
            'disabled_kinds' => 'array',
            'muted_until' => 'datetime',
            'agent_paused' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(['id' => 1], ['disabled_kinds' => [], 'agent_paused' => false]);
    }

    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }

    /** May the bot speak first about this kind right now? Unreadable settings = yes (an alarm must not go quiet by accident). */
    public static function allows(string $kind): bool
    {
        try {
            $s = self::current();

            return ! $s->isMuted() && ! in_array($kind, (array) ($s->disabled_kinds ?? []), true);
        } catch (Throwable) {
            return true;
        }
    }

    public static function agentPaused(): bool
    {
        try {
            return (bool) self::current()->agent_paused;
        } catch (Throwable) {
            return false;
        }
    }
}
