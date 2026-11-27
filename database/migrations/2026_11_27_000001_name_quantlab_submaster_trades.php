<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The sub-master's QuantLab trades get their own strategy name (2026-11-27,
 * "there must be VWMA-Reversion - QuantLab" on Admin → Strategies).
 *
 * QuantLab began sending the sub-master's signals on 20 Nov 2026 at 03:48 UTC
 * under "VWMA-Reversion" — the same name the master's TradingView alerts send —
 * so on the Strategies page they would fold into the master's card. From the
 * same day QuantLab's seven sub-master alerts send "VWMA-Reversion - QuantLab";
 * this renames what arrived before that:
 * - closed trades of sub-master accounts since QuantLab started;
 * - the strategy tag of their positions still open (a close without its own
 *   `strategy` reads it back).
 *
 * Only the label moves: no P&L, fee, size or date. The Signal Log keeps what
 * each alert actually said (`trade_logs` is not touched).
 *
 * down() puts "VWMA-Reversion" back on every sub-master row carrying the new
 * name — including any QuantLab sent with the new name after this ran, which
 * is what undoing it means (one card again).
 */
return new class extends Migration
{
    private const OLD = 'VWMA-Reversion';

    private const NEW = 'VWMA-Reversion - QuantLab';

    /** AdminInsights::SUBMASTER_STRATEGIES_SINCE */
    private const SINCE = '2026-11-20 03:48:00';

    private const VENUES = ['binance', 'mexc', 'bybit'];

    public function up(): void
    {
        $this->rename(self::OLD, self::NEW, self::SINCE);
    }

    public function down(): void
    {
        $this->rename(self::NEW, self::OLD, null);
    }

    private function rename(string $from, string $to, ?string $since): void
    {
        $owners = DB::table('user_credentials')->where('type', 'submaster')->pluck('uni_id')->all();
        if ($owners === []) {
            return;
        }

        foreach (self::VENUES as $venue) {
            $accounts = "{$venue}_accounts";
            $closed = "{$venue}_pastpositions";
            if (! Schema::hasTable($accounts) || ! Schema::hasTable($closed)) {
                continue;
            }
            // Soft-deleted accounts included: a reconnected key still traded.
            $keys = DB::table($accounts)->whereIn('uni_id', $owners)->where('demo', 0)->pluck('api_key')->all();
            if ($keys === []) {
                continue;
            }

            DB::table($closed)
                ->whereIn('api_key', $keys)
                ->where('strategy', $from)
                ->when($since, fn ($q) => $q->where('closed_at', '>=', $since))
                ->update(['strategy' => $to]);

            DB::table('open_strategies')
                ->where('exchange', $venue)
                ->whereIn('api_key', $keys)
                ->where('strategy', $from)
                ->update(['strategy' => $to]);
        }
    }
};
