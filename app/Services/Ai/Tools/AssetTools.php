<?php

namespace App\Services\Ai\Tools;

use App\Http\Controllers\AssetController;
use Illuminate\Http\Request;

/**
 * The coins the bot trades and how they are set up, read through the admin
 * Trading Assets screen's own code (AssetController), plus who changed what.
 */
final class AssetTools implements Toolset
{
    public function definitions(): array
    {
        return [
            'assets' => [
                'title' => 'Trading assets and their settings',
                'description' => 'Every coin set up for trading, per exchange: switched on or off, whether the kill switch '
                    .'turned it off and why, LONG/SHORT/both, base entry size and maximum position size (in coins), how '
                    .'many entries may stack, streak sizing steps, and a size change waiting for an open position to close.',
                'schema' => Props::object([
                    'exchange' => Props::exchange(),
                    'coin' => Props::coin(),
                ]),
            ],
            'asset_changes' => [
                'title' => 'Asset change history',
                'description' => 'Who changed which coin\'s settings and when (from → to), newest first: size changes, '
                    .'switching on/off, kill switch trips, changes waiting for a position to close. Recorded since 14 Oct 2026.',
                'schema' => Props::object([
                    'exchange' => Props::exchange(),
                    'coin' => Props::coin(),
                    'limit' => Props::limit(50, 200),
                ]),
            ],
        ];
    }

    public function call(string $tool, array $arguments): array
    {
        $args = new Args($arguments);
        $exchange = $args->enum('exchange', ['binance', 'bybit', 'mexc']);
        $coin = $args->str('coin');

        return match ($tool) {
            'assets' => $this->assets($exchange, $coin),
            'asset_changes' => $this->changes($exchange, $coin, $args->int('limit', 50, 1, 200)),
        };
    }

    private function assets(?string $exchange, ?string $coin): array
    {
        $assets = app(AssetController::class)->index()->getData(true)['assets'] ?? [];
        $rows = [];
        foreach ($assets as $a) {
            if ($exchange && strtolower((string) ($a['broker'] ?? '')) !== $exchange) {
                continue;
            }
            if ($coin !== null && Coins::of((string) ($a['ticker'] ?? '')) !== Coins::of($coin)) {
                continue;
            }
            $base = (float) ($a['base_size'] ?? 0);
            $max = (float) ($a['max_increments'] ?? 0);
            $rows[] = [
                'id' => $a['asset_id'] ?? null,
                'coin' => Coins::of((string) ($a['ticker'] ?? '')),
                'ticker' => $a['ticker'] ?? null,
                'exchange' => strtolower((string) ($a['broker'] ?? '')),
                'switched_on' => (bool) ($a['enabled'] ?? false),
                'sides' => $a['side'] ?? null,
                'base_entry_size' => $a['base_size'] ?? null,
                // The column is named max_increments but holds a SIZE; the count is derived.
                'max_position_size' => $a['max_increments'] ?? null,
                'max_entries' => $base > 0 && $max > 0 ? max(1, (int) round($max / $base)) : null,
                'kill_switch_after_losses' => $a['kill_switch_losses'] ?? null,
                'kill_switch_tripped_at' => $a['killed_at'] ?? null,
                'kill_switch_reason' => $a['killed_reason'] ?? null,
                'streak_sizing_on' => (bool) ($a['streak_sizing_enabled'] ?? false),
                'streak_sizes' => $a['streak_sizes'] ?? [],
                'size_change_waiting' => $a['pending_sizing'] ?? null,
                'master_balance_on_exchange' => $a['main_account']['balance'] ?? null,
                'last_signal_price' => $a['main_account']['price'] ?? null,
                'updated_at' => $a['updated_at'] ?? null,
            ];
        }

        return [
            'count' => count($rows),
            'switched_on' => count(array_filter($rows, fn ($r) => $r['switched_on'])),
            'assets' => $rows,
        ];
    }

    private function changes(?string $exchange, ?string $coin, int $limit): array
    {
        $query = ['limit' => $coin === null ? $limit : 200];
        if ($exchange) {
            $query['broker'] = match ($exchange) {
                'binance' => 'Binance',
                'bybit' => 'Bybit',
                'mexc' => 'MEXC',
            };
        }
        $changes = app(AssetController::class)->history(Request::create('/', 'GET', $query))->getData(true)['changes'] ?? [];
        if ($coin !== null) {
            $changes = array_values(array_filter($changes, fn ($c) => Coins::of((string) ($c['ticker'] ?? '')) === Coins::of($coin)));
        }

        return [
            'changes' => array_map(fn (array $c) => [
                'when' => $c['created_at'] ?? null,
                'coin' => Coins::of((string) ($c['ticker'] ?? '')),
                'exchange' => strtolower((string) ($c['broker'] ?? '')),
                'event' => $c['event'] ?? null,
                'by' => ($c['is_system'] ?? false) ? 'the system (automatic)' : ($c['actor_name'] ?? null),
                'changes' => $c['changes'] ?? null,
                'note' => $c['note'] ?? null,
            ], array_slice($changes, 0, $limit)),
        ];
    }
}
