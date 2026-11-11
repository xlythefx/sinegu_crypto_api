<?php

namespace Tests\Feature;

/**
 * Admin → Invoice History's stat cards. A $0 month (a loss, or still under the
 * high-water mark) is stored `paid` but collected nothing, so it is neither a
 * paid nor an outstanding invoice: it is counted as "no fee" on its own
 * (2026-11-11 — "Paid invoices: 13" read as 13 payers against 9).
 */
class AdminInvoiceSummaryTest extends PaymentTestCase
{
    public function test_zero_fee_months_are_counted_apart_from_paid_and_outstanding(): void
    {
        $uniId = $this->makeUser();
        $account = $this->makeAccount($uniId);
        $this->makeInvoice($account, $uniId, ['month_year' => '2026-07', 'total_fee' => 24.04, 'status' => 'paid']);
        $this->makeInvoice($account, $uniId, ['month_year' => '2026-08', 'total_fee' => 0, 'status' => 'paid']);
        $this->makeInvoice($account, $uniId, ['month_year' => '2026-09', 'total_fee' => 0, 'status' => 'pending']);
        $this->makeInvoice($account, $uniId, ['month_year' => '2026-10', 'total_fee' => 12.34, 'status' => 'overdue']);

        $summary = $this->getJson('/api/admin/invoices', $this->userHeaders($this->makeUser(['type' => 'admin'])))
            ->assertOk()
            ->json('summary');

        $this->assertSame(4, $summary['count_total']);
        $this->assertSame(1, $summary['count_paid']);
        $this->assertSame(1, $summary['count_pending']);
        $this->assertSame(2, $summary['count_no_fee']);
        $this->assertEquals(24.04, $summary['total_collected']);
        $this->assertEquals(12.34, $summary['outstanding_amount']);
    }
}
