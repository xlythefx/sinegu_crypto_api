<?php

namespace Tests\Feature;

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

    public function test_known_but_unwired_exchange_is_400(): void
    {
        $this->getJson('/api/engine/bybit/accounts', $this->engineHeaders())
            ->assertStatus(400)
            ->assertJson(['success' => false, 'error_code' => 'EXCHANGE_NOT_SUPPORTED']);
    }

    public function test_unknown_exchange_is_404(): void
    {
        $this->getJson('/api/engine/kraken/accounts', $this->engineHeaders())
            ->assertStatus(404);
    }
}
