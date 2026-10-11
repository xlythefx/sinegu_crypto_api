<?php

namespace Tests\Feature;

use App\Models\TradeLog;
use App\Models\UserCredential;
use App\Services\Admin\SignalHealth;

/**
 * Signal Log health: normal skips are told apart from problems, problems are
 * grouped by cause, and the list can be narrowed to the accounts inside each
 * signal (outcome, reason, customer).
 */
class SignalHealthTest extends EngineTestCase
{
    private function headers(): array
    {
        $uniId = $this->makeUser(['type' => 'admin']);

        return ['Authorization' => 'Bearer '.UserCredential::find($uniId)->createToken('spa')->plainTextToken];
    }

    private function signal(array $details, array $overrides = []): TradeLog
    {
        $filled = count(array_filter($details, fn ($d) => ($d['status'] ?? '') === 'filled'));
        $failed = count(array_filter($details, fn ($d) => ($d['status'] ?? '') === 'failed'));

        return TradeLog::create(array_merge([
            'exchange' => 'binance', 'action' => 'BUY', 'ticker' => 'LTCUSDT',
            'success' => $failed === 0, 'category' => 'signal',
            'target_count' => count($details), 'filled' => $filled, 'failed' => $failed,
            'skipped' => count($details) - $filled - $failed,
            'ts' => now()->subHour()->toDateTimeString(),
            'details' => $details,
        ], $overrides));
    }

    private function filled(string $account, bool $master = false): array
    {
        return ['account' => $account, 'uni_id' => 'u-'.$account, 'exchange' => 'binance', 'status' => 'filled', 'is_master' => $master, 'quantity' => 5];
    }

    private function skipped(string $account, string $reason): array
    {
        return ['account' => $account, 'uni_id' => 'u-'.$account, 'exchange' => 'binance', 'status' => 'skipped', 'reason' => $reason, 'is_master' => false];
    }

    private function failed(string $account, string $error, bool $master = false): array
    {
        return ['account' => $account, 'uni_id' => 'u-'.$account, 'exchange' => 'binance', 'status' => 'failed', 'error' => $error, 'is_master' => $master];
    }

    // ---- classification -----------------------------------------------------

    public function test_normal_skips_and_problems_are_told_apart(): void
    {
        $cases = [
            [['status' => 'skipped', 'reason' => 'no long position to close'], 'nothing_to_close', 'normal'],
            [['status' => 'skipped', 'reason' => 'maxed sizing'], 'maxed', 'normal'],
            [['status' => 'skipped', 'reason' => 'deposit below minimum'], 'deposit_low', 'normal'],
            [['status' => 'skipped', 'reason' => 'api key blocked'], 'key_blocked', 'problem'],
            [['status' => 'skipped', 'reason' => 'deposit unknown'], 'deposit_unknown', 'problem'],
            [['status' => 'skipped', 'reason' => 'size too small'], 'size_too_small', 'problem'],
            [['status' => 'skipped', 'reason' => 'stack depth unknown'], 'stack_unknown', 'problem'],
            [['status' => 'failed', 'error' => 'Invalid API-key, IP, or permissions for action'], 'key_blocked', 'problem'],
            [['status' => 'failed', 'error' => 'Margin is insufficient.'], 'insufficient_margin', 'problem'],
            [['status' => 'failed', 'error' => 'no response'], 'no_response', 'problem'],
            [['status' => 'failed', 'error' => 'crash: boom'], 'engine_error', 'problem'],
            [['status' => 'failed', 'error' => 'Precision is over the maximum'], 'order_failed', 'problem'],
            // The engine names the network a coin is missing from (2026-10-11).
            [['status' => 'skipped', 'reason' => 'not listed on MEXC Demo'], 'not_listed_demo', 'normal'],
            [['status' => 'skipped', 'reason' => 'not listed on MEXC Production'], 'not_listed', 'problem'],
            [['status' => 'skipped', 'reason' => 'not listed on Binance Demo'], 'not_listed_demo', 'normal'],
            // A failed read quotes its cause: a timeout is still "no answer"…
            [['status' => 'failed', 'error' => 'could not read positions (GET /api/v1/private/position/open_positions: timed out)'], 'no_response', 'problem'],
            // …and anything else is a read failure, not "order refused".
            [['status' => 'failed', 'error' => 'could not read positions (GET /fapi/v3/positionRisk: HTTP 503)'], 'positions_unreadable', 'problem'],
            [['status' => 'failed', 'error' => 'could not read positions'], 'positions_unreadable', 'problem'],
        ];
        foreach ($cases as [$detail, $key, $severity]) {
            $c = SignalHealth::classify($detail);
            $this->assertSame($key, $c['key'], json_encode($detail));
            $this->assertSame($severity, $c['severity'], json_encode($detail));
        }
        $this->assertNull(SignalHealth::classify(['status' => 'filled']));
        $this->assertSame('rejected', SignalHealth::classify(['reason' => 'asset_not_configured'], 'rejected')['key']);
    }

    // ---- the list -----------------------------------------------------------

    public function test_rows_carry_the_verdict_the_master_and_the_split(): void
    {
        $this->signal([
            $this->filled('Master', true),
            $this->skipped('Flat', 'no long position to close'),
            $this->failed('Broken', 'Invalid API-key, IP, or permissions for action'),
        ]);

        $res = $this->getJson('/api/admin/trade-logs', $this->headers())->assertOk();
        $log = $res->json('logs.0');

        $this->assertSame('filled', $log['master']);
        $this->assertSame(1, $log['problems']);
        $this->assertSame(1, $log['normal_skips']);
        $byAccount = collect($log['details'])->keyBy('account');
        $this->assertSame('nothing_to_close', $byAccount['Flat']['reason_key']);
        $this->assertSame('normal', $byAccount['Flat']['severity']);
        $this->assertSame('API key refused by the exchange', $byAccount['Broken']['reason_label']);
        $this->assertSame('problem', $byAccount['Broken']['severity']);
        $this->assertNull($byAccount['Master']['severity']);
        $this->assertTrue($byAccount['Master']['is_master']);
        $this->assertSame(1, $res->json('summary.problem_accounts'));
        $this->assertSame(1, $res->json('summary.normal_skips'));
        $this->assertSame(['key_blocked', 'nothing_to_close'], array_column($res->json('filters.reasons'), 'key'));
    }

    public function test_the_account_filters_narrow_to_matching_accounts(): void
    {
        $this->signal([$this->filled('Alpha'), $this->skipped('Beta', 'maxed sizing')]);
        $this->signal([$this->filled('Alpha'), $this->failed('Gamma', 'no response')], ['ts' => now()->subMinutes(30)->toDateTimeString()]);
        $h = $this->headers();

        $failed = $this->getJson('/api/admin/trade-logs?outcome=failed', $h)->assertOk();
        $this->assertSame(1, $failed->json('summary.signals'));
        $rows = collect($failed->json('logs.0.details'))->keyBy('account');
        $this->assertTrue($rows['Gamma']['match']);
        $this->assertFalse($rows['Alpha']['match']);

        $this->assertSame(1, $this->getJson('/api/admin/trade-logs?outcome=skipped', $h)->json('summary.signals'));
        $this->assertSame(2, $this->getJson('/api/admin/trade-logs?outcome=filled', $h)->json('summary.signals'));
        $this->assertSame(1, $this->getJson('/api/admin/trade-logs?outcome=problem', $h)->json('summary.signals'));
        $this->assertSame(1, $this->getJson('/api/admin/trade-logs?reason=maxed', $h)->json('summary.signals'));
        $this->assertSame(1, $this->getJson('/api/admin/trade-logs?account=gam', $h)->json('summary.signals'));
        $this->assertSame(0, $this->getJson('/api/admin/trade-logs?account=nobody', $h)->json('summary.signals'));
    }

    public function test_the_customer_search_matches_the_owner_name_too(): void
    {
        $uni = $this->makeUser(['name' => 'Priya Natarajan']);
        $this->signal([['account' => 'Main', 'uni_id' => $uni, 'status' => 'filled']]);

        $this->assertSame(1, $this->getJson('/api/admin/trade-logs?account=priya', $this->headers())->json('summary.signals'));
    }

    // ---- health -------------------------------------------------------------

    public function test_health_is_healthy_when_every_account_filled(): void
    {
        $this->signal([$this->filled('Master', true), $this->filled('A')]);

        $this->getJson('/api/admin/trade-logs/health', $this->headers())
            ->assertOk()
            ->assertJsonPath('health.status', 'healthy')
            ->assertJsonPath('health.signals', 1)
            ->assertJsonPath('health.issues', []);
    }

    public function test_health_is_normal_with_only_expected_skips(): void
    {
        $this->signal([$this->filled('Master', true), $this->skipped('A', 'no short position to close')]);

        $this->getJson('/api/admin/trade-logs/health', $this->headers())
            ->assertOk()
            ->assertJsonPath('health.status', 'normal')
            ->assertJsonPath('health.normal_skips', 1)
            ->assertJsonPath('health.problems', 0);
    }

    public function test_health_needs_attention_and_groups_a_repeating_problem(): void
    {
        foreach ([50, 40, 30] as $m) {
            $this->signal(
                [$this->filled('Master', true), $this->failed('Broken', 'Invalid API-key, IP, or permissions for action')],
                ['ts' => now()->subMinutes($m)->toDateTimeString()],
            );
        }

        $health = $this->getJson('/api/admin/trade-logs/health', $this->headers())->assertOk()->json('health');

        $this->assertSame('attention', $health['status']);
        $this->assertSame(3, $health['problems']);
        $this->assertSame(1, $health['issues_total']);
        $this->assertSame('key_blocked', $health['issues'][0]['reason_key']);
        $this->assertSame(3, $health['issues'][0]['count']);
        $this->assertSame('Broken', $health['issues'][0]['account']);
        $this->assertSame(['label' => 'Fix in API Keys', 'href' => '/admin/api-keys'], $health['issues'][0]['fix']);
    }

    public function test_the_master_missing_a_signal_needs_attention(): void
    {
        $this->signal([$this->failed('Master', 'no response', true), $this->filled('A')]);

        $this->getJson('/api/admin/trade-logs/health', $this->headers())
            ->assertOk()
            ->assertJsonPath('health.status', 'attention')
            ->assertJsonPath('health.master_missed', 1);
    }

    /** A manual close narrowed to customers leaves the master out on purpose. */
    public function test_a_master_left_out_of_the_fan_out_or_with_nothing_to_close_is_not_a_miss(): void
    {
        $this->signal([$this->filled('A')], ['action' => 'EXIT_LONG']);   // is_master false on every row
        $this->signal([['account' => 'Master', 'status' => 'skipped', 'reason' => 'no long position to close', 'is_master' => true]],
            ['action' => 'EXIT_LONG']);

        $this->getJson('/api/admin/trade-logs/health', $this->headers())
            ->assertOk()
            ->assertJsonPath('health.master_missed', 0)
            ->assertJsonPath('health.status', 'normal');
    }

    public function test_health_is_quiet_without_signals_and_ignores_old_ones(): void
    {
        $this->signal([$this->failed('Broken', 'no response')], ['ts' => now()->subDays(3)->toDateTimeString()]);

        $health = $this->getJson('/api/admin/trade-logs/health', $this->headers())
            ->assertOk()
            ->assertJsonPath('health.status', 'quiet')
            ->json('health');

        $this->assertNotNull($health['last_signal_at']);   // when the last one came, even outside the window
    }

    public function test_a_rejected_signal_is_a_problem_grouped_by_coin(): void
    {
        $this->signal([['reason' => 'asset_not_configured', 'exchange' => 'binance']], [
            'category' => 'rejected', 'success' => false, 'target_count' => 0, 'ticker' => 'DOGEUSDT',
        ]);

        $issue = $this->getJson('/api/admin/trade-logs/health', $this->headers())->json('health.issues.0');

        $this->assertSame('rejected', $issue['reason_key']);
        $this->assertSame(['DOGEUSDT'], $issue['tickers']);
        $this->assertNull($issue['account']);
    }

    // ---- issues -------------------------------------------------------------

    public function test_issues_group_problems_and_list_normal_skips_apart(): void
    {
        $this->signal([$this->failed('Broken', 'no response'), $this->skipped('Flat', 'no long position to close')]);
        $this->signal([$this->failed('Broken', 'no response'), $this->skipped('Full', 'maxed sizing')]);
        $this->signal([$this->failed('Old', 'no response')], ['ts' => now()->subDays(10)->toDateTimeString()]);

        $res = $this->getJson('/api/admin/trade-logs/issues', $this->headers())->assertOk();

        $this->assertCount(1, $res->json('issues'));   // the 10-day-old one is outside the default 7 days
        $this->assertSame(2, $res->json('issues.0.count'));
        $this->assertSame('no_response', $res->json('issues.0.reason_key'));
        $this->assertEqualsCanonicalizing(['nothing_to_close', 'maxed'], array_column($res->json('normal'), 'key'));

        $wide = $this->getJson('/api/admin/trade-logs/issues?from='.now()->subDays(30)->toDateString(), $this->headers());
        $this->assertCount(2, $wide->json('issues'));
    }

    public function test_the_health_and_issues_are_admin_only(): void
    {
        $trader = $this->makeUser();
        $h = ['Authorization' => 'Bearer '.UserCredential::find($trader)->createToken('spa')->plainTextToken];

        $this->getJson('/api/admin/trade-logs/health', $h)->assertStatus(403);
        $this->getJson('/api/admin/trade-logs/issues', $h)->assertStatus(403);
    }
}
