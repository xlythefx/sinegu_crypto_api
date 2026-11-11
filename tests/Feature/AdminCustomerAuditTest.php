<?php

namespace Tests\Feature;

use App\Models\TradeLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * The admin audit (2026-11-11): "we don't know how it was paid", and what the
 * boss wants to see about a customer without asking.
 *
 *  - Mark paid by hand records HOW the money came, a note, and WHO marked it.
 *  - Every admin action on a customer is recorded (who, what, before → after).
 *  - User Management and the profile say whether the bot is trading for a
 *    customer and, if not, why; what they owe; how they last paid.
 *  - The profile's "this month" fee is an estimate, not an always-$0 sum.
 *  - A Timeline + notes, and the customer's last signals.
 */
class AdminCustomerAuditTest extends PaymentTestCase
{
    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake();
        $this->admin = $this->makeUser(['type' => 'admin', 'name' => 'Chris Admin']);
    }

    private function as(): array
    {
        return $this->userHeaders($this->admin);
    }

    // ---- mark paid ---------------------------------------------------------

    public function test_marking_paid_by_hand_needs_how_and_a_note(): void
    {
        $uni = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($uni), $uni);

        $this->putJson("/api/admin/invoices/{$invoice->id}", ['status' => 'paid'], $this->as())
            ->assertStatus(422)->assertJsonValidationErrors(['method', 'note']);
        $this->assertSame('pending', $invoice->fresh()->status);

        $this->putJson("/api/admin/invoices/{$invoice->id}",
            ['status' => 'paid', 'method' => 'bank_transfer', 'note' => 'Wise transfer, ref 4471'], $this->as())
            ->assertOk();

        $fresh = $invoice->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame($this->admin, $fresh->paid_by);
        $this->assertSame('bank_transfer', $fresh->manual_method);
        $this->assertSame('Wise transfer, ref 4471', $fresh->payment_note);

        $payment = $this->getJson("/api/admin/invoices/{$invoice->id}", $this->as())->json('invoice.payment');
        $this->assertSame('manual', $payment['kind']);
        $this->assertSame('Bank transfer (marked paid by an admin)', $payment['label']);
        $this->assertSame('Chris Admin', $payment['marked_by']['name']);
        $this->assertSame('Wise transfer, ref 4471', $payment['note']);

        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_uni_id' => $this->admin, 'subject_uni_id' => $uni, 'action' => 'invoice.marked_paid',
        ]);
    }

    public function test_a_sandbox_rehearsal_needs_no_method(): void
    {
        $uni = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($uni), $uni);

        $this->putJson("/api/admin/invoices/{$invoice->id}", ['status' => 'paid', 'sandbox' => true], $this->as())->assertOk();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseMissing('admin_audit_logs', ['action' => 'invoice.marked_paid']);
    }

    // ---- admin actions are recorded ------------------------------------------

    public function test_fee_and_status_changes_are_recorded_with_who_did_them(): void
    {
        $uni = $this->makeUser(['name' => 'Dana Trader', 'realized_percentage' => 20, 'unrealized_percentage' => 6]);

        $this->putJson("/api/admin/users/{$uni}", ['realized_percentage' => 15, 'status' => 'suspended'], $this->as())->assertOk();

        $rows = DB::table('admin_audit_logs')->where('subject_uni_id', $uni)->orderBy('id')->get();
        $this->assertSame(['user.status', 'user.fees'], $rows->pluck('action')->all());
        $this->assertSame('Suspended Dana Trader', $rows[0]->summary);
        $this->assertStringContainsString('realized 20% → 15%', $rows[1]->summary);
        $this->assertSame($this->admin, $rows[1]->actor_uni_id);
    }

    // ---- who is trading, who owes ----------------------------------------------

    public function test_the_users_list_says_who_is_trading_and_why_not(): void
    {
        $trading = $this->makeUser(['name' => 'Trading Tom']);
        $this->makeAccount($trading, ['initial_deposit' => 2000]);

        $paused = $this->makeUser(['name' => 'Paused Pam']);
        $acc = $this->makeAccount($paused, ['enabled' => 0, 'initial_deposit' => 2000]);
        $owed = $this->makeInvoice($acc, $paused, ['status' => 'overdue', 'due_date' => now()->subDays(5)->toDateString(), 'total_fee' => 24.04]);

        $blocked = $this->makeUser(['name' => 'Blocked Ben']);
        $this->makeAccount($blocked, ['key_status' => 'blocked', 'key_blocked_at' => now()->subDay(), 'initial_deposit' => 2000]);

        $small = $this->makeUser(['name' => 'Small Sam']);
        $this->makeAccount($small, ['initial_deposit' => 400]);

        $none = $this->makeUser(['name' => 'Nobody Nia']);

        $payer = $this->makeUser(['name' => 'Payer Pat']);
        $pacc = $this->makeAccount($payer, ['initial_deposit' => 2000]);
        $paid = $this->makeInvoice($pacc, $payer, ['total_fee' => 12.5]);
        app(\App\Services\InvoiceService::class)->settle($paid, 'stripe', 'cs_live_x', notify: false);

        $users = collect($this->getJson('/api/admin/users', $this->as())->assertOk()->json('users'))->keyBy('uni_id');

        $this->assertSame('trading', $users[$trading]['trading']['state']);
        $this->assertSame('paused_unpaid', $users[$paused]['trading']['state']);
        $this->assertSame($owed->id, $users[$paused]['trading']['invoice_id']);
        $this->assertEquals(24.04, $users[$paused]['owes']['amount']);
        $this->assertTrue($users[$paused]['owes']['overdue']);
        $this->assertSame('key_blocked', $users[$blocked]['trading']['state']);
        $this->assertTrue($users[$blocked]['key_problem']);
        $this->assertSame('below_min', $users[$small]['trading']['state']);
        $this->assertSame('no_exchange', $users[$none]['trading']['state']);
        $this->assertSame('card', $users[$payer]['last_payment']['kind']);
        $this->assertSame('Stripe', $users[$payer]['last_payment']['label']);
        $this->assertSame('blocked', collect($users[$blocked]['accounts'])->first()['key_status']);
    }

    public function test_a_collaborator_gets_no_billing_in_the_list(): void
    {
        $this->makeAccount($this->makeUser());
        $collab = $this->makeUser(['type' => 'collaborator']);

        $row = $this->getJson('/api/admin/users', $this->userHeaders($collab))->assertOk()->json('users.0');

        foreach (['trading', 'owes', 'last_payment', 'pnl_month'] as $key) {
            $this->assertArrayNotHasKey($key, $row);
        }
    }

    public function test_the_profile_says_why_an_account_is_off_and_who_referred_them(): void
    {
        $referrer = $this->makeUser(['name' => 'Ref Rita']);
        $uni = $this->makeUser();
        DB::table('referral_tracking')->insert(['referral_code' => 'ABC', 'referrer_uni_id' => $referrer, 'referred_user_uni_id' => $uni, 'created_at' => now()]);
        $acc = $this->makeAccount($uni, ['enabled' => 0]);
        $this->makeInvoice($acc, $uni, ['status' => 'overdue', 'due_date' => now()->subDays(3)->toDateString()]);

        $user = $this->getJson("/api/admin/users/{$uni}", $this->as())->assertOk()->json('user');

        $this->assertSame('paused_unpaid', $user['accounts'][0]['trading']['state']);
        $this->assertSame('paused_unpaid', $user['standing']['trading']['state']);
        $this->assertSame('Ref Rita', $user['referred_by']['name']);
        $this->assertArrayHasKey('contacts', $user);
    }

    public function test_this_months_fee_is_an_estimate_not_a_sum_of_missing_invoices(): void
    {
        $uni = $this->makeUser();
        $this->makeAccount($uni, ['initial_deposit' => 1000, 'balance' => 1000]);

        $commissions = $this->getJson("/api/admin/users/{$uni}/summary", $this->as())->assertOk()->json('summary.commissions');

        $this->assertArrayHasKey('month_estimate', $commissions);
        $this->assertNotNull($commissions['month_estimate']);
        $this->assertArrayHasKey('last_invoice', $commissions);
    }

    // ---- timeline, notes, signals ------------------------------------------------

    public function test_the_timeline_tells_the_story_and_takes_notes(): void
    {
        $uni = $this->makeUser();
        $invoice = $this->makeInvoice($this->makeAccount($uni), $uni);
        $this->putJson("/api/admin/invoices/{$invoice->id}", ['status' => 'paid', 'method' => 'cash', 'note' => 'Paid at the office'], $this->as())->assertOk();
        $this->postJson("/api/admin/users/{$uni}/notes", ['body' => 'Promised to top up Friday'], $this->as())->assertCreated();

        $events = collect($this->getJson("/api/admin/users/{$uni}/timeline", $this->as())->assertOk()->json('events'));

        $paid = $events->firstWhere('kind', 'payment');
        $this->assertStringContainsString('Cash', $paid['detail']);
        $this->assertSame('Chris Admin', $paid['by']);
        $note = $events->firstWhere('kind', 'note');
        $this->assertSame('Promised to top up Friday', $note['detail']);
        $this->assertNotNull($events->firstWhere('kind', 'signup'));
        $this->assertNotNull($events->firstWhere('kind', 'account'));

        $this->deleteJson("/api/admin/users/{$uni}/notes/{$note['note_id']}", [], $this->as())->assertOk();
        $this->assertDatabaseCount('user_notes', 0);
    }

    public function test_a_collaborator_cannot_read_the_timeline(): void
    {
        $uni = $this->makeUser();
        $collab = $this->makeUser(['type' => 'collaborator']);

        $this->getJson("/api/admin/users/{$uni}/timeline", $this->userHeaders($collab))->assertForbidden();
    }

    public function test_the_last_signals_say_why_the_bot_skipped(): void
    {
        $uni = $this->makeUser();
        $this->makeAccount($uni);
        TradeLog::create([
            'exchange' => 'binance', 'action' => 'BUY', 'ticker' => 'LTCUSDT', 'success' => false,
            'strategy' => 'ABCD-v1', 'category' => 'signal', 'target_count' => 2,
            'filled' => 1, 'skipped' => 1, 'failed' => 0, 'ts' => now(),
            'details' => [
                ['uni_id' => $uni, 'account' => 'Main', 'status' => 'skipped', 'reason' => 'deposit below minimum'],
                ['uni_id' => 'someone-else', 'status' => 'filled'],
            ],
        ]);

        $res = $this->getJson("/api/admin/users/{$uni}/signals", $this->as())->assertOk();

        $this->assertCount(1, $res->json('signals'));
        $this->assertSame('skipped', $res->json('signals.0.status'));
        $this->assertSame('Deposit below the minimum', $res->json('signals.0.reason.label'));
        $this->assertSame(1, $res->json('last_7_days.skipped'));
    }
}
