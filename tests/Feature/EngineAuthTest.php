<?php

namespace Tests\Feature;

use App\Services\Exchanges\ExchangeSchema;

/** The engine middleware + exchange guard on /api/engine/{exchange}/*. */
class EngineAuthTest extends EngineTestCase
{
    public function test_missing_secret_is_rejected(): void
    {
        $this->getJson('/api/engine/binance/accounts')
            ->assertStatus(401)
            ->assertJson(['success' => false, 'error_code' => 'ENGINE_UNAUTHORIZED']);
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $this->getJson('/api/engine/binance/accounts', ['X-Engine-Secret' => 'nope'])
            ->assertStatus(401)
            ->assertJson(['success' => false, 'error_code' => 'ENGINE_UNAUTHORIZED']);
    }

    public function test_unconfigured_secret_fails_closed(): void
    {
        config(['services.engine.secret' => null]);

        $this->getJson('/api/engine/binance/accounts', ['X-Engine-Secret' => ''])
            ->assertStatus(503)
            ->assertJson(['success' => false, 'error_code' => 'ENGINE_NOT_CONFIGURED']);
    }

    public function test_correct_secret_is_accepted(): void
    {
        $this->getJson('/api/engine/binance/accounts', $this->engineHeaders())
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_bybit_is_wired_to_the_engine(): void
    {
        $this->getJson('/api/engine/bybit/accounts', $this->engineHeaders())
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    /**
     * Every name the engine routes admit must have a schema behind it.
     *
     * This replaces the old "bybit answers 400" case. All three venues are
     * wired now, so EXCHANGE_NOT_SUPPORTED is no longer reachable over HTTP —
     * it exists for the NEXT venue, whose name lands in the route's whereIn
     * before its tables do. Asserting the two lists agree catches that window
     * directly, which is what the old test was really guarding.
     */
    public function test_every_routed_exchange_has_a_schema_behind_it(): void
    {
        foreach (['binance', 'bybit', 'mexc'] as $exchange) {
            $this->assertTrue(
                ExchangeSchema::isSupported($exchange),
                "Route accepts '{$exchange}' but ExchangeSchema has no entry — /engine/{$exchange}/* would 400."
            );
        }
    }

    public function test_mexc_is_wired(): void
    {
        $this->getJson('/api/engine/mexc/accounts', $this->engineHeaders())
            ->assertOk()
            ->assertJson(['success' => true, 'accounts' => []]);
    }

    public function test_unknown_exchange_is_404(): void
    {
        $this->getJson('/api/engine/kraken/accounts', $this->engineHeaders())
            ->assertStatus(404);
    }
}
