<?php

namespace Tests\Feature;

use App\Console\Commands\EngineWatch;
use App\Http\Controllers\AdminUserController;
use App\Mail\RiskAlertNotice;
use App\Models\RiskAlert;
use App\Models\UserCredential;
use App\Services\Assets\StreakSizing;
use App\Services\Engine\WebhookPaths;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * The `submaster` role (2026-11-18) and the high-risk alerts built with it.
 *
 * A sub-master's accounts trade ONLY the sub-master webhooks (`feed:
 * submaster`), never the main TradingView feed, and nobody else ever trades
 * the sub-master's signals. Every fill is audited on ingest (FeedAudit): an
 * account outside the row's feed is a breach — stamped on the row, emailed to
 * TRADE_ALERT_EMAILS, posted to the admin Telegram (through the engine) and
 * listed on the Overview. The other high-risk kinds share the same path.
 */
class SubmasterRoleTest extends EngineTestCase
{
    private const ADMIN_SECRET = 'test-admin-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.engine.admin_secret' => self::ADMIN_SECRET,
            'services.engine.targets.local' => 'http://engine.test:5010',
            'mail.trade_alerts' => 'christian@example.test, paolo@example.test',
        ]);
        Http::fake(['engine.test:5010/*' => Http::response(['success' => true], 200)]);
        Mail::fake();
    }

    private function actor(string $uniId): static
    {
        $this->app['auth']->forgetGuards();
        $token = UserCredential::find($uniId)->createToken('spa')->plainTextToken;

        return $this->withHeaders(['Authorization' => 'Bearer '.$token]);
    }

    /** POST a signal row the way the engine does. */
    private function tradeLog(string $feed, string $action, array $details, array $extra = [])
    {
        return $this->postJson('/api/engine/binance/trade-logs', array_merge([
            'action' => $action,
            'ticker' => 'LTCUSDT',
            'success' => true,
            'category' => 'signal',
            'target_count' => count($details),
            'filled' => count(array_filter($details, fn ($d) => ($d['status'] ?? null) === 'filled')),
            'details' => $details,
            'ts' => now()->utc()->format('Y-m-d H:i:s'),
            'feed' => $feed,
        ], $extra), $this->engineHeaders());
    }

    private function filled(string $uniId, string $account = 'Main'): array
    {
        return ['account' => $account, 'uni_id' => $uniId, 'exchange' => 'binance', 'status' => 'filled', 'quantity' => 1];
    }

    // ---- the role --------------------------------------------------------------

    public function test_the_role_is_assignable_and_has_no_admin_portal(): void
    {
        $this->assertContains(UserCredential::SUBMASTER, AdminUserController::ROLES);
        $this->assertSame('submaster', UserCredential::SUBMASTER);
        $this->assertNotContains(UserCredential::SUBMASTER, StreakSizing::MASTER_FOLLOWERS);

        $sub = $this->makeUser(['type' => 'submaster']);
        $this->actor($sub)->getJson('/api/admin/users')->assertForbidden();
        $this->actor($sub)->getJson('/api/admin/insights/overview')->assertForbidden();
        $this->actor($sub)->getJson('/api/dashboard/summary')->assertOk();
    }

    public function test_the_engine_lists_sub_master_accounts_on_their_own_feed(): void
    {
        $sub = $this->makeUser(['type' => 'submaster']);
        $user = $this->makeUser();
        $master = $this->makeUser(['type' => 'master']);
        foreach ([$sub, $user, $master] as $u) {
            $this->makeAccount($u);
        }

        $rows = collect($this->getJson('/api/engine/binance/accounts', $this->engineHeaders())
            ->assertOk()->json('accounts'))->keyBy('uni_id');

        $this->assertSame('submaster', $rows[$sub]['feed']);
        $this->assertFalse($rows[$sub]['is_master']);
        $this->assertSame('main', $rows[$user]['feed']);
        $this->assertSame('main', $rows[$master]['feed']);
        $this->assertSame('/binance_submaster_webhook', WebhookPaths::path('binance', 'submaster'));
    }

    public function test_moving_into_or_out_of_sub_master_waits_until_flat(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $target = $this->makeUser(['name' => 'Open Olga']);
        $account = $this->makeAccount($target);
        DB::table('binance_positions')->insert([
            'api_key' => DB::table('binance_accounts')->where('id', $account)->value('api_key'),
            'uni_id' => $target, 'symbol' => 'LTCUSDT', 'position_side' => 'LONG', 'position_amt' => 3,
            'entry_price' => 100, 'mark_price' => 101, 'unrealized_profit' => 3, 'created_at' => now(),
        ]);

        $this->actor($admin)->putJson("/api/admin/users/{$target}", ['type' => 'submaster'])
            ->assertStatus(409)->assertJsonPath('error_code', 'OPEN_POSITIONS');
        // Tester ↔ sub-master is a feed change too.
        DB::table('user_credentials')->where('uni_id', $target)->update(['type' => 'tester']);
        $this->actor($admin)->putJson("/api/admin/users/{$target}", ['type' => 'submaster'])
            ->assertStatus(409);

        DB::table('binance_positions')->delete();
        $this->actor($admin)->putJson("/api/admin/users/{$target}", ['type' => 'submaster'])
            ->assertOk()->assertJsonPath('user.type', 'submaster');
    }

    // ---- the breach audit ------------------------------------------------------

    public function test_a_clean_sub_master_fill_is_no_breach(): void
    {
        $sub = $this->makeUser(['type' => 'submaster']);
        $id = $this->tradeLog('submaster', 'BUY', [$this->filled($sub)])->assertCreated()->json('trade_log_id');

        $this->assertNull(DB::table('trade_logs')->where('id', $id)->value('breach'));
        $this->assertSame(0, RiskAlert::count());
        Mail::assertNothingSent();
    }

    public function test_a_customer_filled_from_the_sub_master_feed_is_a_breach_everywhere(): void
    {
        $sub = $this->makeUser(['type' => 'submaster']);
        $customer = $this->makeUser(['name' => 'Carla Customer']);
        $id = $this->tradeLog('submaster', 'BUY', [
            $this->filled($sub, 'Dima'),
            $this->filled($customer, 'Carla main'),
            ['account' => 'Skipped', 'uni_id' => $this->makeUser(), 'status' => 'skipped', 'reason' => 'deposit below minimum'],
        ])->assertCreated()->json('trade_log_id');

        // The proof on the row: the one account that should not have filled.
        $breach = json_decode(DB::table('trade_logs')->where('id', $id)->value('breach'), true);
        $this->assertCount(1, $breach);
        $this->assertSame($customer, $breach[0]['uni_id']);
        $this->assertSame('main', $breach[0]['expected_feed']);

        $alert = RiskAlert::sole();
        $this->assertSame(RiskAlert::FEED_BREACH, $alert->kind);
        $this->assertSame($id, $alert->trade_log_id);
        $this->assertNotNull($alert->emailed_at);
        $this->assertNotNull($alert->telegram_at);
        Mail::assertSent(RiskAlertNotice::class, fn (RiskAlertNotice $m) => $m->hasTo('christian@example.test')
            && $m->hasTo('paolo@example.test') && str_contains(implode(' ', $m->lines), 'Carla Customer'));
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'http://engine.test:5010/admin/risk-alert'
            && $r->header('X-Admin-Secret') === [self::ADMIN_SECRET]
            && str_contains(json_encode($r->data()), 'sub-master feed'));

        // The Signal Log row carries it.
        $admin = $this->makeUser(['type' => 'admin']);
        $row = collect($this->actor($admin)->getJson('/api/admin/trade-logs?feed=submaster')->assertOk()->json('logs'))
            ->firstWhere('id', $id);
        $this->assertSame($customer, $row['breach'][0]['uni_id']);
    }

    public function test_a_sub_master_filled_from_the_main_feed_is_a_breach(): void
    {
        $sub = $this->makeUser(['type' => 'submaster']);
        $this->tradeLog('main', 'SELL', [$this->filled($this->makeUser(['type' => 'master'])), $this->filled($sub)])
            ->assertCreated();

        $this->assertSame(1, RiskAlert::where('kind', RiskAlert::FEED_BREACH)->count());
        $this->assertStringContainsString('main feed', RiskAlert::sole()->title);
    }

    public function test_exits_are_never_judged(): void
    {
        // The admin's manual close may reach any feed's account on purpose.
        $sub = $this->makeUser(['type' => 'submaster']);
        $this->tradeLog('main', 'EXIT_LONG', [$this->filled($sub), $this->filled($this->makeUser())])->assertCreated();

        $this->assertSame(0, RiskAlert::count());
    }

    // ---- the other high-risk kinds ---------------------------------------------

    public function test_a_house_key_refused_raises_and_its_recovery_closes_it(): void
    {
        $sub = $this->makeUser(['type' => 'submaster', 'name' => 'Dima Sub']);
        $account = $this->makeAccount($sub);
        $apiKey = DB::table('binance_accounts')->where('id', $account)->value('api_key');
        $customerKey = DB::table('binance_accounts')->where('id', $this->makeAccount($this->makeUser()))->value('api_key');

        $this->postJson('/api/engine/binance/key-status', ['api_key' => $customerKey, 'status' => 'blocked', 'code' => '-2015'], $this->engineHeaders())->assertOk();
        $this->assertSame(0, RiskAlert::count(), "a customer's own key is not a house alert");

        $this->postJson('/api/engine/binance/key-status', ['api_key' => $apiKey, 'status' => 'blocked', 'code' => '-2015', 'message' => 'Invalid API-key'], $this->engineHeaders())->assertOk();
        $alert = RiskAlert::sole();
        $this->assertSame(RiskAlert::KEY_REFUSED, $alert->kind);
        $this->assertStringContainsString('sub-master', $alert->title);
        $this->assertStringNotContainsString($apiKey, json_encode($alert->lines), 'never the key itself');
        Mail::assertSent(RiskAlertNotice::class, 1);

        $this->postJson('/api/engine/binance/key-status', ['api_key' => $apiKey, 'status' => 'ok'], $this->engineHeaders())->assertOk();
        $this->assertNotNull($alert->fresh()->resolved_at);
    }

    public function test_the_same_problem_repeating_counts_without_flooding_the_inbox(): void
    {
        $reject = fn () => $this->tradeLog('main', 'BUY', [['reason' => 'assets_unavailable', 'exchange' => 'binance']],
            ['category' => 'rejected', 'success' => false, 'filled' => 0])->assertCreated();
        $reject();
        $reject();

        $alert = RiskAlert::sole();
        $this->assertSame(RiskAlert::ASSETS_UNAVAILABLE, $alert->kind);
        $this->assertSame(2, $alert->occurrences);
        Mail::assertSent(RiskAlertNotice::class, 1);

        // An hour later the same problem is emailed again.
        $this->travel(61)->minutes();
        $reject();
        Mail::assertSent(RiskAlertNotice::class, 2);
        $this->assertSame(3, $alert->fresh()->occurrences);

        // The test feed's coin list is not house money.
        RiskAlert::query()->delete();
        $this->tradeLog('test', 'BUY', [['reason' => 'assets_unavailable']], ['category' => 'rejected', 'success' => false, 'filled' => 0]);
        $this->assertSame(0, RiskAlert::count());
    }

    public function test_the_engine_watch_alerts_after_two_misses_and_closes_itself(): void
    {
        // Another host: the setUp fake answers every engine.test call 200.
        config(['services.engine.health_watch' => true, 'services.engine.targets.local' => 'http://engine-down.test:5010']);
        Http::fake(['engine-down.test:5010/health' => Http::response('down', 502), 'engine-up.test:5010/health' => Http::response(['status' => 'ok'])]);

        $this->artisan('engine:watch')->assertSuccessful();
        $this->assertSame(0, RiskAlert::count(), 'one miss is a blip');
        $this->artisan('engine:watch')->assertSuccessful();
        $alert = RiskAlert::sole();
        $this->assertSame(RiskAlert::ENGINE_DOWN, $alert->kind);
        Mail::assertSent(RiskAlertNotice::class, 1);

        config(['services.engine.targets.local' => 'http://engine-up.test:5010']);
        $this->artisan('engine:watch')->assertSuccessful();
        $this->assertNotNull($alert->fresh()->resolved_at);
        $this->assertNull(Cache::get(EngineWatch::MISSES_KEY));
    }

    public function test_the_engine_watch_is_off_where_no_engine_is_expected(): void
    {
        config(['services.engine.health_watch' => false, 'services.engine.targets.local' => 'http://engine-down.test:5010']);
        Http::fake(['engine-down.test:5010/health' => Http::response('down', 502)]);
        $this->artisan('engine:watch')->assertSuccessful();
        $this->artisan('engine:watch')->assertSuccessful();
        $this->assertSame(0, RiskAlert::count());
    }

    // ---- the Overview -----------------------------------------------------------

    public function test_open_alerts_are_on_the_overview_until_resolved_and_hidden_from_collaborators(): void
    {
        $sub = $this->makeUser(['type' => 'submaster']);
        $this->tradeLog('main', 'BUY', [$this->filled($sub)])->assertCreated();
        $alert = RiskAlert::sole();

        $admin = $this->makeUser(['type' => 'admin', 'name' => 'Ada Admin']);
        $this->actor($admin)->getJson('/api/admin/insights/overview')->assertOk()
            ->assertJsonPath('attention.risk_alerts.0.id', $alert->id)
            ->assertJsonPath('attention.risk_alerts.0.kind', 'feed_breach');

        $collab = $this->makeUser(['type' => 'collaborator']);
        $this->assertArrayNotHasKey('risk_alerts', $this->actor($collab)->getJson('/api/admin/insights/overview')->assertOk()->json('attention'));
        $this->actor($collab)->postJson("/api/admin/risk-alerts/{$alert->id}/resolve")->assertForbidden();

        $this->actor($admin)->postJson("/api/admin/risk-alerts/{$alert->id}/resolve", ['note' => 'Closed by hand'])
            ->assertOk();
        $this->assertSame('Ada Admin', $alert->fresh()->resolved_by_name);
        $this->actor($admin)->postJson("/api/admin/risk-alerts/{$alert->id}/resolve")->assertStatus(409);
        $this->assertSame([], $this->actor($admin)->getJson('/api/admin/insights/overview')->json('attention.risk_alerts'));
    }

    // ---- the manual-trade console -------------------------------------------------

    public function test_the_console_sends_a_sub_master_signal_with_its_own_secret_to_its_own_path(): void
    {
        config([
            'services.engine.submaster_webhook_secret' => 'sub-secret',
            'services.engine.webhook_secrets.binance' => 'main-secret',
            'services.engine.targets.prod' => 'http://engine.test:5010',
        ]);
        $admin = $this->makeUser(['type' => 'admin']);
        $this->actor($admin)->postJson('/api/admin/manual-trade/send', [
            'exchange' => 'binance', 'feed' => 'submaster', 'target' => 'prod', 'action' => 'BUY', 'symbol' => 'LTCUSDT',
        ])->assertOk();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'http://engine.test:5010/binance_submaster_webhook'
            && $r['secret'] === 'sub-secret');
        Http::assertNotSent(fn (HttpRequest $r) => ($r['secret'] ?? null) === 'main-secret');
    }
}
