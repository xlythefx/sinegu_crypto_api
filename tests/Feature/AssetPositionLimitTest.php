<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * POST /api/admin/assets/position-limit — the asset form's check of a proposed
 * size against Binance's position limit, relayed from the engine.
 */
class AssetPositionLimitTest extends PaymentTestCase
{
    private string $adminUniId;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.engine.admin_secret' => 'engine-admin-test',
            'services.engine.targets.local' => 'http://engine.test',
        ]);
        $this->adminUniId = $this->makeUser(['type' => 'admin']);
    }

    private function propose(array $body): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->userHeaders($this->adminUniId))
            ->postJson('/api/admin/assets/position-limit', $body);
    }

    public function test_relays_the_engines_answer_with_the_proposal_and_the_admin_secret(): void
    {
        Http::fake(['engine.test/admin/size-check' => Http::response([
            'success' => true, 'cap_usd' => 10000, 'over_count' => 0, 'accounts' => [],
        ])]);

        $this->propose(['ticker' => 'santosusdt', 'base_size' => 400, 'max_increments' => 1200, 'leverage' => 10])
            ->assertOk()->assertJson(['cap_usd' => 10000, 'over_count' => 0]);

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'http://engine.test/admin/size-check'
            && $r->header('X-Admin-Secret') === ['engine-admin-test']
            && $r['exchange'] === 'binance' && $r['ticker'] === 'SANTOSUSDT'
            && $r['base_size'] == 400 && $r['max_size'] == 1200 && $r['leverage'] === 10);
    }

    public function test_no_leverage_is_sent_as_null_so_the_engine_assumes_its_default(): void
    {
        Http::fake(['engine.test/*' => Http::response(['success' => true])]);
        $this->propose(['ticker' => 'SANTOSUSDT', 'base_size' => 400])->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r['leverage'] === null && $r['max_size'] == 0);
    }

    public function test_an_engine_failure_is_a_503_with_its_message_never_a_502(): void
    {
        Http::fake(['engine.test/*' => Http::response(['error' => 'LIMITS_UNAVAILABLE', 'message' => 'Binance did not answer.'], 502)]);
        $this->propose(['ticker' => 'SANTOSUSDT', 'base_size' => 400])
            ->assertStatus(503)->assertJson(['error' => 'LIMITS_UNAVAILABLE', 'message' => 'Binance did not answer.']);
    }

    public function test_an_engine_refusal_of_the_request_keeps_its_4xx(): void
    {
        Http::fake(['engine.test/*' => Http::response(['error' => 'NO_ACCOUNTS', 'message' => 'No real-money account.'], 404)]);
        $this->propose(['ticker' => 'SANTOSUSDT', 'base_size' => 400])->assertStatus(404)->assertJson(['error' => 'NO_ACCOUNTS']);
    }

    public function test_bad_input_is_refused_before_the_engine_is_called(): void
    {
        Http::fake();
        $this->propose(['ticker' => 'SAN TOS', 'base_size' => 400])->assertStatus(422);
        $this->propose(['ticker' => 'SANTOSUSDT', 'base_size' => 0])->assertStatus(422);
        $this->propose(['ticker' => 'SANTOSUSDT', 'base_size' => 1, 'leverage' => 200])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_a_plain_user_is_refused(): void
    {
        $this->withHeaders($this->userHeaders($this->makeUser()))
            ->postJson('/api/admin/assets/position-limit', ['ticker' => 'SANTOSUSDT', 'base_size' => 1])
            ->assertForbidden();
    }
}
