<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The month's closed P&L BEFORE exchange fees, and the fees between it and
 * `realized_pnl`, stored on the invoice (2026-11-10, after Dima read a user's
 * October as +$190 before fees and could not see how the bill came to 20% of
 * +$120.18). The invoice shows "+189.70 before fees, −69.52 exchange fees =
 * +120.18" from its own row, so it still restates and never recomputes.
 *
 * Backfill: gross = SUM(realized_pnl + COALESCE(exchange_fee, 0)) over the
 * month's closes. That figure survives every fee rebase (FeeRebase keeps
 * realized_pnl + exchange_fee constant), so it is the same today as when the
 * invoice was made. The fees are then gross − the invoice's OWN realized_pnl,
 * so the three figures on a row always add up, even where a fee was rebased
 * after the invoice was issued. Binance only: no other venue is invoiced yet.
 * Rows whose closes are gone (a purged account) stay NULL — "not recorded",
 * never a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('realized_pnl_gross', 20, 8)->nullable()->after('realized_pnl');
            $table->decimal('exchange_fees', 20, 8)->nullable()->after('realized_pnl_gross');
        });

        DB::table('invoices')
            ->where('exchange', 'binance')
            ->whereNotNull('api_key')
            ->orderBy('id')
            ->get(['id', 'api_key', 'month_year', 'realized_pnl'])
            ->each(function ($inv) {
                $period = Carbon::createFromFormat('!Y-m', (string) $inv->month_year);
                $closes = DB::table('binance_pastpositions')
                    ->where('api_key', $inv->api_key)
                    ->whereBetween('closed_at', [$period->copy()->startOfMonth(), $period->copy()->endOfMonth()]);

                if (! (clone $closes)->exists()) {
                    // No closes on record: a $0 month is still exactly $0 / $0.
                    if ((float) $inv->realized_pnl == 0.0) {
                        DB::table('invoices')->where('id', $inv->id)->update([
                            'realized_pnl_gross' => 0,
                            'exchange_fees' => 0,
                        ]);
                    }

                    return;
                }

                $gross = (float) $closes->sum(DB::raw('realized_pnl + COALESCE(exchange_fee, 0)'));
                DB::table('invoices')->where('id', $inv->id)->update([
                    'realized_pnl_gross' => $gross,
                    'exchange_fees' => $gross - (float) $inv->realized_pnl,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['realized_pnl_gross', 'exchange_fees']);
        });
    }
};
