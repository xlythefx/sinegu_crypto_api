<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * An open position's ENTRY price reaches the admin screens beside its mark.
 * On 13 Oct one customer's RENDER short read 2.212 while everyone else's read
 * 2.0505 — the entry is what tells an admin that account holds a different
 * position, and the screens were showing only the current price.
 */
class AdminOpenPositionEntryPriceTest extends EngineTestCase
{
    public function test_admin_position_screens_carry_the_entry_price(): void
    {
        $uni = $this->makeUser(['name' => 'Alex']);
        $this->makeAccount($uni, ['api_key' => 'alex-key', 'name' => 'BOT ALEX G']);
        DB::table('binance_positions')->insert([
            'api_key' => 'alex-key', 'uni_id' => $uni, 'symbol' => 'RENDERUSDT',
            'position_side' => 'SHORT', 'position_amt' => -240,
            'entry_price' => 2.212, 'mark_price' => 2.12, 'unrealized_profit' => 22.08,
        ]);

        $admin = $this->makeUser(['type' => 'admin']);
        $user = \App\Models\UserCredential::where('uni_id', $admin)->firstOrFail();
        $headers = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

        $this->withHeaders($headers)->getJson('/api/admin/positions')
            ->assertOk()
            ->assertJsonPath('positions.0.entry_price', 2.212)
            ->assertJsonPath('positions.0.price', 2.12);
        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers)->getJson("/api/admin/users/{$uni}/positions")
            ->assertOk()
            ->assertJsonPath('positions.0.entry_price', 2.212)
            ->assertJsonPath('positions.0.price', 2.12);
    }
}
