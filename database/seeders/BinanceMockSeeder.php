<?php

namespace Database\Seeders;

use App\Models\UserCredential;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mock Binance data for local development, attached to the test account
 * (test@sinegu.com). Wipes and re-fills the binance_* tables on every run.
 */
class BinanceMockSeeder extends Seeder
{
    public function run(): void
    {
        $user = UserCredential::where('email', 'test@sinegu.com')->firstOrFail();
        $uniId = $user->uni_id;

        // Re-runnable: clear previous mock rows (children first, FK-safe)
        DB::table('binance_invoices')->delete();
        DB::table('binance_transactions')->delete();
        DB::table('binance_pastpositions')->delete();
        DB::table('binance_positions')->delete();
        DB::table('binance_accounts')->delete();

        $mainKey = 'mock_main_' . str_repeat('a1b2c3d4', 12); // 106 chars, clearly fake
        $demoKey = 'mock_demo_' . str_repeat('e5f6a7b8', 12);

        // ---- Accounts -------------------------------------------------
        $mainId = DB::table('binance_accounts')->insertGetId([
            'api_key' => $mainKey,
            'uni_id' => $uniId,
            'secret_key' => 'mock_secret_' . str_repeat('00', 20),
            'name' => 'Main Futures',
            'balance' => 12480.55,
            'unrealized_pnl' => 342.18,
            'initial_deposit' => 10000.00,
            'currency_type' => 'USDT',
            'created_at' => Carbon::now()->subDays(60),
            'demo' => 0,
            'enabled' => 1,
        ]);

        DB::table('binance_accounts')->insert([
            'api_key' => $demoKey,
            'uni_id' => $uniId,
            'secret_key' => 'mock_secret_' . str_repeat('11', 20),
            'name' => 'Demo Account',
            'balance' => 5000.00,
            'unrealized_pnl' => 0,
            'initial_deposit' => 5000.00,
            'currency_type' => 'USDT',
            'created_at' => Carbon::now()->subDays(20),
            'demo' => 1,
            'enabled' => 1,
        ]);

        // ---- Open positions (Main Futures) ----------------------------
        $positions = [
            ['BTCUSDT', 'LONG', 0.04500000, 116250.00, 118480.00, 100.35, 5331.60],
            ['ETHUSDT', 'LONG', 1.20000000, 4118.50, 4262.75, 173.10, 5115.30],
            ['SOLUSDT', 'SHORT', -25.00000000, 224.60, 221.85, 68.75, 5546.25],
        ];
        foreach ($positions as $i => [$symbol, $side, $amt, $entry, $mark, $upnl, $notional]) {
            DB::table('binance_positions')->insert([
                'api_key' => $mainKey,
                'uni_id' => $uniId,
                'symbol' => $symbol,
                'position_side' => $side,
                'position_amt' => $amt,
                'entry_price' => $entry,
                'mark_price' => $mark,
                'unrealized_profit' => $upnl,
                'notional' => $notional,
                'initial_margin' => round($notional / 10, 8), // 10x leverage
                'maint_margin' => round($notional * 0.004, 8),
                'update_time' => Carbon::now()->subMinutes(5 + $i)->getTimestampMs(),
                'created_at' => Carbon::now()->subMinutes(5 + $i),
            ]);
        }

        // ---- Closed positions (append-style history) ------------------
        $past = [
            // [daysAgo, symbol, side, amt, entry, exit, pnl, closeSide, strategy]
            [42, 'BTCUSDT', 'LONG', 0.05000000, 108300.00, 111150.00, 142.50, 'SELL', 'Momentum Breakout'],
            [38, 'ETHUSDT', 'LONG', 1.50000000, 3820.00, 3945.00, 187.50, 'SELL', 'Momentum Breakout'],
            [33, 'SOLUSDT', 'SHORT', -30.00000000, 236.40, 228.10, 249.00, 'BUY', 'Mean Reversion'],
            [29, 'BTCUSDT', 'SHORT', -0.04000000, 113900.00, 115200.00, -52.00, 'BUY', 'Mean Reversion'],
            [24, 'XRPUSDT', 'LONG', 1800.00000000, 3.12, 3.29, 306.00, 'SELL', 'Grid Bot'],
            [19, 'ETHUSDT', 'LONG', 2.00000000, 3990.00, 4085.00, 190.00, 'SELL', 'Momentum Breakout'],
            [15, 'BNBUSDT', 'LONG', 6.00000000, 792.00, 771.50, -123.00, 'SELL', 'Grid Bot'],
            [11, 'BTCUSDT', 'LONG', 0.06000000, 114750.00, 117300.00, 153.00, 'SELL', 'Momentum Breakout'],
            [6, 'SOLUSDT', 'LONG', 20.00000000, 213.20, 222.90, 194.00, 'SELL', 'Mean Reversion'],
            [2, 'ETHUSDT', 'SHORT', -1.00000000, 4310.00, 4256.00, 54.00, 'BUY', 'Scalper'],
        ];
        foreach ($past as $i => [$days, $symbol, $side, $amt, $entry, $exit, $pnl, $closeSide, $strategy]) {
            DB::table('binance_pastpositions')->insert([
                'api_key' => $mainKey,
                'uni_id' => $uniId,
                'symbol' => $symbol,
                'position_side' => $side,
                'position_amt' => $amt,
                'entry_price' => $entry,
                'exit_price' => $exit,
                'realized_pnl' => $pnl,
                'side' => $closeSide,
                'order_id' => 900000100 + $i,
                'closed_at' => Carbon::now()->subDays($days)->setTime(14, 30),
                'strategy' => $strategy,
                'created_at' => Carbon::now()->subDays($days)->setTime(14, 30),
            ]);
        }

        // ---- Wallet transactions --------------------------------------
        $transactions = [
            // [daysAgo, type, amount, balance_after, tranId]
            [60, 'DEPOSIT', 10000.00, 10000.00, 700000201],
            [30, 'DEPOSIT', 2500.00, 13100.00, 700000202],
            [12, 'WITHDRAWAL', 1000.00, 12350.00, 700000203],
        ];
        foreach ($transactions as [$days, $type, $amount, $after, $tranId]) {
            $when = Carbon::now()->subDays($days)->setTime(9, 15);
            DB::table('binance_transactions')->insert([
                'api_key' => $mainKey,
                'uni_id' => $uniId,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $after,
                'tran_id' => $tranId,
                'currency' => 'USDT',
                'transaction_time' => $when->getTimestampMs(),
                'info' => 'TRANSFER',
                'created_at' => $when,
            ]);
        }

        // ---- Invoices (20% of realized profit) ------------------------
        DB::table('binance_invoices')->insert([
            [
                'user_id' => $uniId,
                'account_id' => $mainId,
                'api_key' => $mainKey,
                'month_year' => '2026-06',
                'equity_start' => 10000.00,
                'equity_end' => 11225.00,
                'realized_pnl' => 1225.00,
                'unrealized_pnl' => 180.00,
                'deposit_amount' => 12500.00,
                'adjusted_equity' => 11225.00,
                'capital_flow' => 2500.00,
                'performance_equity' => 11225.00,
                'hwm_before' => 10000.00,
                'hwm_after' => 11225.00,
                'new_realized_profit' => 1225.00,
                'new_unrealized_profit' => 180.00,
                'fee_realized' => 245.00,   // 20% of 1225
                'fee_unrealized' => 10.80,  // 6% of 180
                'total_fee' => 255.80,
                'status' => 'paid',
                'due_date' => '2026-07-08',
                'invoice_sent_at' => '2026-07-01 08:00:00',
                'created_at' => '2026-07-01 08:00:00',
            ],
            [
                'user_id' => $uniId,
                'account_id' => $mainId,
                'api_key' => $mainKey,
                'month_year' => '2026-07',
                'equity_start' => 11225.00,
                'equity_end' => 12822.73,
                'realized_pnl' => 570.00,
                'unrealized_pnl' => 342.18,
                'deposit_amount' => 11500.00,
                'adjusted_equity' => 12822.73,
                'capital_flow' => -1000.00,
                'performance_equity' => 12822.73,
                'hwm_before' => 11225.00,
                'hwm_after' => 12822.73,
                'new_realized_profit' => 570.00,
                'new_unrealized_profit' => 342.18,
                'fee_realized' => 114.00,   // 20% of 570
                'fee_unrealized' => 20.53,  // 6% of 342.18
                'total_fee' => 134.53,
                'status' => 'pending',
                'due_date' => '2026-08-08',
                'invoice_sent_at' => null,
                'created_at' => Carbon::now(),
            ],
        ]);
    }
}
