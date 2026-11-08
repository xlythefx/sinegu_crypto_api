<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Laravel\Sanctum\Sanctum;

/**
 * Admin → Sandbox → Invoice Scenarios, run in CI.
 *
 * Every scenario's expected figures were derived by hand from the billing
 * rules (SandboxInvoiceController::scenarios), so the catalogue is the
 * billing spec — until 2026-11-08 nothing ran it unless an admin pressed
 * Run. A rule change that breaks one of them now fails the suite.
 */
class InvoiceScenariosTest extends EngineTestCase
{
    public function test_every_scenario_passes(): void
    {
        $uniId = $this->makeUser(['type' => 'admin']);
        Sanctum::actingAs(UserCredential::query()->find($uniId));

        $res = $this->postJson('/api/admin/sandbox/invoice-scenarios/run', ['uni_id' => $uniId])
            ->assertOk();

        $failed = collect($res->json('results'))
            ->reject(fn ($r) => $r['pass'])
            ->map(fn ($r) => $r['key'].': '.collect($r['checks'])
                ->reject(fn ($c) => $c['pass'])
                ->map(fn ($c) => "{$c['field']} expected {$c['expected']} got {$c['actual']}")
                ->implode(', '))
            ->values()
            ->all();

        $this->assertSame([], $failed);
        $this->assertGreaterThanOrEqual(13, (int) $res->json('passed'));
    }
}
