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
            // [ticker, side, max_increments (max position size), base_size, enabled]
            ['BTCUSDT', 'ALL', 0.500, 0.005, true],
            ['ETHUSDT', 'ALL', 5.000, 0.050, true],
            ['SOLUSDT', 'ALL', 100.000, 1.000, true],
            ['XRPUSDT', 'LONG', 5000.000, 100.000, true],
            ['BNBUSDT', 'ALL', 20.000, 0.200, true],
            ['DOGEUSDT', 'LONG', 20000.000, 500.000, true],
            ['ADAUSDT', 'ALL', 8000.000, 200.000, false],
            ['LINKUSDT', 'SHORT', 300.000, 5.000, true],
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
