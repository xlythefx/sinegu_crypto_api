<?php

namespace Database\Seeders;

use App\Models\Asset;
use Illuminate\Database\Seeder;

/**
 * Mock Binance crypto assets for local development.
 */
class AssetSeeder extends Seeder
{
    public function run(): void
    {
        $assets = [
            // Real asset set from the mother dashboard (all enabled).
            // NOTE: in the binance_abcd engine, max_increments is a STACK COUNT
            // (how many base_size entries may stack), not an absolute max size.
            // BTCUSDT uses the mother's intent: 0.012 max / 0.004 base = 3 stacks.
            // [ticker, side, max_increments (stacks), base_size, enabled]
            ['ALGOUSDT', 'ALL', 3.000, 8500.000, true],
            ['BTCUSDT', 'ALL', 3.000, 0.004, true],
            ['ETHUSDT', 'ALL', 3.000, 0.050, true],
            ['FETUSDT', 'ALL', 3.000, 120.000, true],
            ['LTCUSDT', 'ALL', 3.000, 14.000, true],
        ];

        foreach ($assets as [$ticker, $side, $maxIncrements, $baseSize, $enabled]) {
            Asset::updateOrCreate(
                ['ticker' => $ticker, 'broker' => 'Binance'],
                [
                    'type' => 'Cryptocurrency',
                    'side' => $side,
                    'max_increments' => $maxIncrements,
                    'base_size' => $baseSize,
                    'enabled' => $enabled,
                ]
            );
        }
    }
}
