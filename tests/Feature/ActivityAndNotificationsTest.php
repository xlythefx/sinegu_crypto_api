<?php

namespace Tests\Feature;

use App\Mail\ExchangeConnectedNotice;
use App\Mail\NewRegistrationNotice;
use App\Services\Notifications\TeamNotices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Dima's three asks (2026-11-23):
 *  1. a log of each user's OWN actions on their profile (Timeline),
 *  2. one Activity list across every user (Admin → Activity),
 *  3. a Notifications page where he picks who receives each team email.
 */
class ActivityAndNotificationsTest extends PaymentTestCase
{
    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake();
        config(['mail.admin_address' => 'team@example.test, dima@example.test', 'mail.trade_alerts' => 'alerts@example.test']);
        $this->admin = $this->makeUser(['type' => 'admin', 'name' => 'Chris Admin']);
    }

    // ---- 1. the customer's own actions ----------------------------------------

    public function test_a_successful_action_is_logged_and_a_refused_one_is_not(): void
    {
        $uni = $this->makeUser(['name' => 'Sam Carter']);

        $this->putJson('/api/user/password', [
            'current_password' => 'wrong', 'new_password' => 'new-password-1', 'new_password_confirmation' => 'new-password-1',
        ], $this->userHeaders($uni))->assertStatus(422);
        $this->assertDatabaseMissing('user_activity_logs', ['uni_id' => $uni]);

        $this->putJson('/api/user/password', [
            'current_password' => 'secret-password', 'new_password' => 'new-password-1', 'new_password_confirmation' => 'new-password-1',
        ], $this->userHeaders($uni))->assertOk();

        $row = DB::table('user_activity_logs')->where('uni_id', $uni)->first();
        $this->assertSame('password.changed', $row->action);
        $this->assertSame('Changed their password', $row->summary);
        // Never the password itself, in any form.
        $this->assertStringNotContainsString('new-password-1', json_encode($row));
    }

    public function test_connecting_renaming_and_disconnecting_are_logged_without_the_keys(): void
    {
        $uni = $this->makeUser(['name' => 'Sam Carter']);
        $h = $this->userHeaders($uni);

        $id = $this->postJson('/api/exchange/binance', [
            'name' => 'Main', 'api_key' => 'live-key-000000000777', 'secret_key' => 'very-secret-777',
        ], $h)->assertCreated()->json('account.id');
        $this->putJson("/api/exchange/binance/accounts/{$id}", ['name' => 'Renamed'], $h)->assertOk();
        $this->deleteJson("/api/exchange/binance/accounts/{$id}", [], $h)->assertOk();

        $rows = DB::table('user_activity_logs')->where('uni_id', $uni)->orderBy('id')->get();
        $this->assertSame(['exchange.connected', 'exchange.renamed', 'exchange.disconnected'], $rows->pluck('action')->all());
        $this->assertSame('Connected a Binance account "Main" (live)', $rows[0]->summary);
        $this->assertSame('Disconnected their Binance account "Renamed"', $rows[2]->summary);
        $all = json_encode($rows);
        $this->assertStringNotContainsString('live-key-000000000777', $all);
        $this->assertStringNotContainsString('very-secret-777', $all);
    }

    public function test_the_timeline_shows_their_actions_and_sign_ins(): void
    {
        $uni = $this->makeUser(['name' => 'Sam Carter']);
        $this->putJson('/api/user/contacts', ['telegram' => 'sam_tg'], $this->userHeaders($uni))->assertOk();
        DB::table('user_access_events')->insert([
            'uni_id' => $uni, 'event' => 'login', 'method' => 'password', 'ip' => '203.0.113.9', 'country' => 'PH',
            'device_type' => 'desktop', 'browser' => 'Chrome 141', 'os' => 'Windows', 'created_at' => now(),
        ]);

        $events = collect($this->getJson("/api/admin/users/{$uni}/timeline", $this->userHeaders($this->admin))->assertOk()->json('events'));

        $own = $events->firstWhere('kind', 'customer');
        $this->assertSame('Updated their contact details (Telegram)', $own['title']);
        $this->assertStringNotContainsString('sam_tg', json_encode($events->all()));
        $signin = $events->firstWhere('kind', 'signin');
        $this->assertSame('Signed in (email + password)', $signin['title']);
        $this->assertStringContainsString('Chrome 141', $signin['detail']);
    }

    // ---- 2. one list across every user ------------------------------------------

    public function test_the_activity_list_merges_all_three_sources_newest_first(): void
    {
        $uni = $this->makeUser(['name' => 'Sam Carter']);
        $other = $this->makeUser(['name' => 'Alex Moreno']);
        DB::table('user_access_events')->insert([
            'uni_id' => $other, 'event' => 'register', 'method' => 'discord', 'device_type' => 'mobile', 'created_at' => now()->subHours(3),
        ]);
        DB::table('admin_audit_logs')->insert([
            'actor_uni_id' => $this->admin, 'subject_uni_id' => $uni, 'action' => 'user.approved',
            'summary' => 'Approved the account', 'created_at' => now()->subHours(2),
        ]);
        $this->putJson('/api/user/password', [
            'current_password' => 'secret-password', 'new_password' => 'new-password-1', 'new_password_confirmation' => 'new-password-1',
        ], $this->userHeaders($uni))->assertOk();

        $h = $this->userHeaders($this->admin);
        $items = $this->getJson('/api/admin/activity', $h)->assertOk()->json('items');
        $this->assertSame(['customer', 'admin', 'signin'], array_column($items, 'source'));
        $this->assertSame('Sam Carter', $items[0]['subject']['name']);
        $this->assertSame('Chris Admin', $items[1]['actor']['name']);
        $this->assertSame('Signed up (Discord)', $items[2]['title']);

        // Narrowed to one person, by name.
        $only = $this->getJson('/api/admin/activity?q=Alex', $h)->json('items');
        $this->assertSame(['signin'], array_column($only, 'source'));
        // Narrowed to admin actions.
        $this->assertSame(['admin'], array_column($this->getJson('/api/admin/activity?source=admin', $h)->json('items'), 'source'));
    }

    public function test_activity_and_notifications_are_full_admin_only(): void
    {
        $collab = $this->makeUser(['type' => 'collaborator']);
        $user = $this->makeUser();
        foreach ([$collab, $user] as $who) {
            $this->getJson('/api/admin/activity', $this->userHeaders($who))->assertForbidden();
            $this->getJson('/api/admin/notifications', $this->userHeaders($who))->assertForbidden();
        }
    }

    // ---- 3. who receives each team email ------------------------------------------

    public function test_with_nothing_saved_every_notice_uses_the_server_lists(): void
    {
        $this->assertSame(['team@example.test', 'dima@example.test'], TeamNotices::recipients('team-new-registration'));
        $this->assertSame(['alerts@example.test'], TeamNotices::recipients('risk-alert'));

        $notices = collect($this->getJson('/api/admin/notifications', $this->userHeaders($this->admin))->assertOk()->json('notices'));
        $this->assertTrue($notices->firstWhere('slug', 'team-new-registration')['uses_default']);
    }

    public function test_an_admin_sets_the_list_switches_a_notice_off_and_resets_it(): void
    {
        $h = $this->userHeaders($this->admin);

        $this->putJson('/api/admin/notifications/team-new-registration', [
            'enabled' => true, 'recipients' => ['Dima@Example.test', 'dima@example.test', 'boss@example.test'],
        ], $h)->assertOk();
        $this->assertSame(['dima@example.test', 'boss@example.test'], TeamNotices::recipients('team-new-registration'));
        $this->assertDatabaseHas('admin_audit_logs', ['actor_uni_id' => $this->admin, 'action' => 'notification.updated']);

        $this->putJson('/api/admin/notifications/team-new-registration', ['enabled' => false, 'recipients' => ['boss@example.test']], $h)->assertOk();
        $this->assertSame([], TeamNotices::recipients('team-new-registration'));

        $this->putJson('/api/admin/notifications/team-new-registration', ['enabled' => true, 'recipients' => null], $h)->assertOk();
        $this->assertSame(['team@example.test', 'dima@example.test'], TeamNotices::recipients('team-new-registration'));

        $this->putJson('/api/admin/notifications/team-new-registration', ['enabled' => true, 'recipients' => ['not-an-email']], $h)
            ->assertStatus(422)->assertJsonPath('error_code', 'INVALID_EMAIL');
    }

    public function test_a_critical_alert_cannot_be_switched_off_or_left_without_anyone(): void
    {
        $h = $this->userHeaders($this->admin);

        $this->putJson('/api/admin/notifications/risk-alert', ['enabled' => false, 'recipients' => null], $h)
            ->assertStatus(422)->assertJsonPath('error_code', 'NOTICE_CRITICAL');
        $this->putJson('/api/admin/notifications/risk-alert', ['enabled' => true, 'recipients' => []], $h)
            ->assertStatus(422)->assertJsonPath('error_code', 'NOTICE_NEEDS_RECIPIENT');
        $this->assertSame(['alerts@example.test'], TeamNotices::recipients('risk-alert'));
    }

    public function test_the_sign_up_notice_goes_to_the_saved_list(): void
    {
        TeamNotices::save('team-new-registration', true, ['boss@example.test'], $this->admin);
        $uni = $this->makeUser();

        app(\App\Services\Notifications\AccountMail::class)
            ->registrationPending(\App\Models\UserCredential::where('uni_id', $uni)->first(), 'Email + password');

        Mail::assertSent(NewRegistrationNotice::class, fn ($m) => $m->hasTo('boss@example.test') && ! $m->hasTo('team@example.test'));
    }

    public function test_a_customer_connecting_an_exchange_tells_the_team_and_staff_do_not(): void
    {
        TeamNotices::save('team-exchange-connected', true, ['dima@example.test'], $this->admin);
        $uni = $this->makeUser(['name' => 'Sam Carter']);
        $this->postJson('/api/exchange/binance', [
            'name' => 'Main', 'api_key' => 'live-key-000000000888', 'secret_key' => 'x',
        ], $this->userHeaders($uni))->assertCreated();

        Mail::assertSent(ExchangeConnectedNotice::class, fn ($m) => $m->hasTo('dima@example.test') && $m->name === 'Sam Carter' && $m->mode === 'Live');

        $staff = $this->makeUser(['type' => 'tester']);
        $this->postJson('/api/exchange/binance', [
            'name' => 'Staff', 'api_key' => 'live-key-000000000889', 'secret_key' => 'x', 'demo' => true,
        ], $this->userHeaders($staff))->assertCreated();
        Mail::assertSent(ExchangeConnectedNotice::class, 1);
    }
}
