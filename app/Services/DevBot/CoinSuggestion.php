<?php

namespace App\Services\DevBot;

use App\Services\Assets\BinanceSizingCheck;
use App\Services\EngineCache;

/**
 * Size buttons for a coin a signal named but Trading Assets does not have
 * (2026-11-22, Christian: "suggest 5 – 15 dollar sizes and I will pick").
 *
 * The dollars are the MARGIN a $1,000 account puts up per entry — Binance's
 * "Cost" line — at the leverage the coin will trade at: 25×, or Binance's
 * maximum for the coin when that is lower (ASR 10×, DEXE 20×). So a $10 button
 * on a 25× coin is about $250 of position. The amount is then floored to the
 * coin's step, and a button Binance would refuse (under its minimum quantity or
 * minimum order value) is never offered. The figures are approximate on
 * purpose — the price moves, and Christian asked for close, not exact.
 *
 * Bigger accounts trade more: the engine scales every entry by balance ÷
 * $1,000 in tenths (`_scale_qty`). The engine's own size check is asked with a
 * base of 1, so each account's entry size IS its multiple — the engine's math,
 * never a copy of it here.
 *
 * Binance only for now: MEXC / Bybit have no rules reader on this side.
 */
class CoinSuggestion
{
    /** Margin per entry, in dollars, for a $1,000 account. */
    public const COSTS = [5, 10, 15];

    /** Entries per position (max size = this × the entry size). */
    public const ENTRIES = 3;

    /** The leverage the alerts send; capped at the coin's own maximum. */
    public const DEFAULT_LEVERAGE = 25;

    public function __construct(private BinanceSizingCheck $rules, private EngineCache $engine) {}

    /**
     * @return array{ok: bool, error?: string, ticker: string, price?: float, leverage?: int,
     *   max_leverage?: ?int, leverage_known?: bool, step?: string, options?: list<array{cost: int, qty: float, notional: float}>,
     *   accounts?: array{count: int, biggest_multiple: ?float, biggest_is_master: bool}}
     */
    public function build(string $ticker): array
    {
        $symbol = strtoupper(trim($ticker));
        $check = $this->rules->check($symbol);
        $binance = $check['binance'] ?? null;
        if ($binance === null) {
            return ['ok' => false, 'ticker' => $symbol, 'error' => (string) ($check['binance_error'] ?? 'Binance could not be read.')];
        }
        if (! ($binance['listed'] ?? false)) {
            return ['ok' => false, 'ticker' => $symbol, 'error' => "{$symbol} is not listed on Binance USDⓈ-M futures."];
        }
        if (($binance['status'] ?? 'TRADING') !== 'TRADING') {
            return ['ok' => false, 'ticker' => $symbol, 'error' => "{$symbol} is not trading on Binance right now (status {$binance['status']})."];
        }
        $price = (float) ($binance['price'] ?? 0);
        if ($price <= 0) {
            return ['ok' => false, 'ticker' => $symbol, 'error' => (string) ($check['binance_error'] ?? 'Binance did not give a price.')];
        }

        // One engine call: Binance's leverage limit for the coin, and every
        // real-money account's entry multiple (base 1 → entry_qty = multiple).
        $engine = $this->engine->sizeCheck([
            'exchange' => 'binance', 'ticker' => $symbol, 'base_size' => 1.0,
            'max_size' => (float) self::ENTRIES, 'leverage' => self::DEFAULT_LEVERAGE,
        ]);
        $body = is_array($engine['body'] ?? null) && $engine['error'] === null ? $engine['body'] : [];
        $maxLeverage = isset($body['max_leverage']) ? (int) $body['max_leverage'] : null;
        $leverage = $maxLeverage !== null && $maxLeverage > 0
            ? min(self::DEFAULT_LEVERAGE, $maxLeverage)
            : self::DEFAULT_LEVERAGE;

        $options = self::options($price, (string) ($binance['step_size'] ?? ''),
            (float) ($binance['min_qty'] ?? 0), (float) ($binance['min_notional'] ?? 0), $leverage);
        if ($options === []) {
            return ['ok' => false, 'ticker' => $symbol,
                'error' => "Every size from \${$this->costRange()} is under Binance's minimum order for {$symbol}."];
        }

        return [
            'ok' => true,
            'ticker' => $symbol,
            'price' => $price,
            'leverage' => $leverage,
            'max_leverage' => $maxLeverage,
            'leverage_known' => $maxLeverage !== null,
            'step' => (string) ($binance['step_size'] ?? ''),
            'options' => $options,
            'accounts' => self::accountSummary(is_array($body['accounts'] ?? null) ? $body['accounts'] : []),
        ];
    }

    /**
     * The buttons: each margin × leverage ÷ price, floored to the step, minus
     * anything Binance would refuse and any repeat of a smaller button.
     *
     * @return list<array{cost: int, qty: float, notional: float}>
     */
    public static function options(float $price, string $step, float $minQty, float $minNotional, int $leverage, array $costs = self::COSTS): array
    {
        if ($price <= 0 || $leverage <= 0) {
            return [];
        }
        $stepValue = (float) $step;
        $decimals = self::decimals($step);
        $out = [];
        $seen = [];
        foreach ($costs as $cost) {
            $raw = $cost * $leverage / $price;
            $qty = $stepValue > 0 ? round(floor($raw / $stepValue + 1e-9) * $stepValue, $decimals) : $raw;
            $notional = $qty * $price;
            if ($qty <= 0 || ($minQty > 0 && $qty + 1e-12 < $minQty) || ($minNotional > 0 && $notional + 1e-9 < $minNotional)) {
                continue;
            }
            $key = (string) $qty;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['cost' => (int) $cost, 'qty' => $qty, 'notional' => round($notional, 2)];
        }

        return $out;
    }

    /** "1" → 0, "0.001" → 3, "1e-5" style never comes from Binance. */
    public static function decimals(string $step): int
    {
        $step = rtrim(rtrim(trim($step), '0'), '.');
        $dot = strpos($step, '.');

        return $dot === false ? 0 : strlen($step) - $dot - 1;
    }

    /** @return array{count: int, biggest_multiple: ?float, biggest_is_master: bool} */
    private static function accountSummary(array $rows): array
    {
        $biggest = null;
        foreach ($rows as $r) {
            if (! is_array($r) || ! isset($r['entry_qty'])) {
                continue;
            }
            if ($biggest === null || (float) $r['entry_qty'] > (float) $biggest['entry_qty']) {
                $biggest = $r;
            }
        }

        return [
            'count' => count($rows),
            'biggest_multiple' => $biggest ? round((float) $biggest['entry_qty'], 1) : null,
            'biggest_is_master' => (bool) ($biggest['is_master'] ?? false),
        ];
    }

    private function costRange(): string
    {
        return min(self::COSTS).'–$'.max(self::COSTS);
    }
}
