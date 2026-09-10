<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;

/**
 * DELETE /api/admin/sandbox/invoices (global) and
 * DELETE /api/admin/sandbox/users/{uniId}/invoices (one user).
 *
 * One handler, two routes. What must hold in both: a settled invoice is a
 * payment record and is spared unless `include_paid` says otherwise, and the
 * user-scoped call never reaches past its user — the global form is opt-in by
 * URL, not by an empty parameter.
 */
class ClearInvoicesTest extends EngineTestCase
{
    private function headersFor(string $uniId): array
    {
        $user = UserCredential::find($uniId);

        return ['Authorization' => 'Bearer '.$user->createToken('spa')->plainTextToken];
    }

    private function admin(): string
    {
        $this->app['auth']->forgetGuards();

        return $this->makeUser(['type' => 'admin']);
    }

    /** Insert one invoice for a user; returns its id. */
    private function makeInvoice(string $uniId, string $status = 'pending'): int
    {
        static $n = 0;
        $n++;

        return DB::table('invoices')->insertGetId([
            'user_id' => $uniId,
            'account_id' => $n,
            'exchange' => 'binance',
            'api_key' => "key-{$n}",
            'month_year' => '2026-07',
            'total_fee' => 100,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_global_wipe_clears_unpaid_invoices_for_every_user(): void
    {
        $admin = $this->admin();
        $a = $this->makeUser();
        $b = $this->makeUser();

        $this->makeInvoice($a);
        $this->makeInvoice($a);
        $this->makeInvoice($b);
        $paid = $this->makeInvoice($b, 'paid');

        $this->deleteJson('/api/admin/sandbox/invoices', [], $this->headersFor($admin))
            ->assertOk()
            ->assertJsonPath('scope', 'all')
            ->assertJsonPath('deleted', 3)
            ->assertJsonPath('skipped_paid', 1)
            // The figure that tells an admin the scope was what they meant.
            ->assertJsonPath('users', 2);

        $this->assertSame(1, Invoice::count());
        $this->assertDatabaseHas('invoices', ['id' => $paid]);
    }

    public function test_include_paid_takes_the_settled_ones_too(): void
    {
        $admin = $this->admin();
        $user = $this->makeUser();
        $this->makeInvoice($user);
        $this->makeInvoice($user, 'paid');

        $this->deleteJson(
            '/api/admin/sandbox/invoices?include_paid=true',
            [],
            $this->headersFor($admin)
        )
            ->assertOk()
            ->assertJsonPath('deleted', 2)
            ->assertJsonPath('skipped_paid', 0);

        $this->assertSame(0, Invoice::count());
    }

    /** The scoped route must not become the global one by accident. */
    public function test_the_user_scoped_wipe_leaves_other_users_alone(): void
    {
        $admin = $this->admin();
        $mine = $this->makeUser();
        $theirs = $this->makeUser();

        $this->makeInvoice($mine);
        $this->makeInvoice($theirs);

        $this->deleteJson(
            "/api/admin/sandbox/users/{$mine}/invoices",
            [],
            $this->headersFor($admin)
        )
            ->assertOk()
            ->assertJsonPath('scope', 'user')
            ->assertJsonPath('deleted', 1);

        $this->assertSame(0, Invoice::where('user_id', $mine)->count());
        $this->assertSame(1, Invoice::where('user_id', $theirs)->count());
    }

    public function test_an_unknown_user_is_still_a_404_not_a_global_wipe(): void
    {
        $admin = $this->admin();
        $other = $this->makeUser();
        $this->makeInvoice($other);

        $this->deleteJson(
            '/api/admin/sandbox/users/does-not-exist/invoices',
            [],
            $this->headersFor($admin)
        )->assertStatus(404);

        $this->assertSame(1, Invoice::count());
    }

    public function test_a_plain_user_cannot_clear_invoices(): void
    {
        $plain = $this->makeUser();
        $this->makeInvoice($plain);

        $this->deleteJson('/api/admin/sandbox/invoices', [], $this->headersFor($plain))
            ->assertStatus(403);

        $this->assertSame(1, Invoice::count());
    }
}
