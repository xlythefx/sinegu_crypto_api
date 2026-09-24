<?php

namespace Tests\Feature;

use App\Models\UserCredential;

/**
 * The manual-trade console per venue: MEXC targets come from mexc_accounts and
 * the venue has its own webhook path + secret. Kept apart from
 * AdminManualTradeTest because that file exercises the outbound engine call,
 * which crashes PHP natively on the WAMP box; nothing here leaves the process.
 */
class AdminManualTradeMexcTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.engine.webhook_secrets.binance' => 'engine-hook-secret',
            'services.engine.webhook_secrets.mexc' => 'engine-hook-secret',
            'services.engine.targets.local' => 'http://engine.test:5010',
        ]);
    }

    private function admin(): array
    {
        $uniId = $this->makeUser(['type' => 'admin']);
        $user = UserCredential::find($uniId);

        return ['Authorization' => 'Bearer '.$user->createToken('spa')->plainTextToken];
    }

    public function test_mexc_targets_come_from_mexc_accounts_only(): void
    {
        $headers = $this->admin();
        $alice = $this->makeUser(['name' => 'Alice']);
        $this->makeAccount($alice, [], 'binance');
        $bob = $this->makeUser(['name' => 'Bob']);
        $this->makeAccount($bob, ['balance' => 250], 'mexc');
        $this->makeAccount($bob, ['enabled' => 0], 'mexc'); // excluded, same filters as the engine

        $mexc = $this->getJson('/api/admin/manual-trade/targets?exchange=mexc', $headers)->assertOk()->json('targets');
        $this->assertCount(1, $mexc);
        $this->assertSame('Bob', $mexc[0]['display_name']);
        $this->assertSame('mexc', $mexc[0]['exchange']);
        $this->assertSame(1, $mexc[0]['account_count']);
        $this->assertSame(250.0, (float) $mexc[0]['balance']);

        $binance = $this->getJson('/api/admin/manual-trade/targets?exchange=binance', $headers)->assertOk()->json('targets');
        $this->assertSame(['Alice'], array_column($binance, 'display_name'));

        // Bybit has a webhook path of its own as of 2026-09-24, so it answers
        // with its (currently empty) target list rather than refusing.
        $this->getJson('/api/admin/manual-trade/targets?exchange=bybit', $headers)
            ->assertOk()
            ->assertJsonPath('targets', []);

        $this->getJson('/api/admin/manual-trade/targets?exchange=kraken', $headers)
            ->assertStatus(400)
            ->assertJson(['error_code' => 'EXCHANGE_NOT_SUPPORTED']);
    }

    public function test_the_mexc_webhook_secret_falls_back_to_the_binance_one(): void
    {
        // One engine serves both paths, so an .env that only names the Binance
        // token must still let the console sign a MEXC signal.
        config(['services.engine.webhook_secrets.mexc' => null]);
        $this->assertNull(config('services.engine.webhook_secrets.mexc'));

        $config = require base_path('config/services.php');
        $this->assertSame(
            $config['engine']['webhook_secrets']['binance'],
            $config['engine']['webhook_secrets']['mexc'],
        );
    }
}
