<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/** Bot Engine ops endpoints: status, journal tail, restart. */
class AdminEngineTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Pretend we're on the prod box; every shell call is faked below.
        config(['services.engine.systemd' => true]);
    }

    private function admin(): array
    {
        $uniId = $this->makeUser(['type' => 'admin']);
        $user = UserCredential::find($uniId);

        return ['Authorization' => 'Bearer '.$user->createToken('spa')->plainTextToken];
    }

    public function test_requires_admin(): void
    {
        $this->getJson('/api/admin/engine/status')->assertStatus(401);

        $plainUniId = $this->makeUser();
        $plain = UserCredential::find($plainUniId);
        $this->getJson('/api/admin/engine/status', [
            'Authorization' => 'Bearer '.$plain->createToken('spa')->plainTextToken,
        ])->assertStatus(403);
    }

    public function test_status_reports_state_and_health(): void
    {
        Process::fake([
            '*is-active*' => Process::result("active\n"),
            '*ActiveEnterTimestamp*' => Process::result("Wed 2026-07-30 00:18:10 UTC\n"),
        ]);
        Http::fake([
            '127.0.0.1:5010/health' => Http::response([
                'metrics' => ['received' => 3, 'rejected' => 2],
                'pollers' => ['balances'],
                'rate_limited_until' => null,
            ]),
        ]);

        $this->getJson('/api/admin/engine/status', $this->admin())
            ->assertOk()
            ->assertJsonPath('engine.available', true)
            ->assertJsonPath('engine.state', 'active')
            ->assertJsonPath('engine.active_since', 'Wed 2026-07-30 00:18:10 UTC')
            ->assertJsonPath('engine.health.metrics.received', 3)
            ->assertJsonPath('engine.message', null);
    }

    public function test_status_flags_engine_down(): void
    {
        Process::fake([
            '*is-active*' => Process::result("inactive\n", exitCode: 3),
            '*ActiveEnterTimestamp*' => Process::result(''),
        ]);
        Http::fake(['127.0.0.1:5010/*' => Http::response([], 500)]);

        $this->getJson('/api/admin/engine/status', $this->admin())
            ->assertOk()
            ->assertJsonPath('engine.state', 'inactive')
            ->assertJsonPath('engine.health', null)
            ->assertJsonPath('engine.message', 'The engine service is not running.');
    }

    public function test_logs_tail_with_clamped_lines(): void
    {
        Process::fake([
            '*journalctl*' => Process::result("line one\nline two\n"),
        ]);

        $this->getJson('/api/admin/engine/logs?lines=999999', $this->admin())
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('lines.0', 'line one')
            ->assertJsonPath('lines.1', 'line two');

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'journalctl') && str_contains($cmd, '-n 1000');
        });
    }

    public function test_restart_uses_sudo_and_returns_state(): void
    {
        Process::fake([
            'sudo -n systemctl restart*' => Process::result(''),
            '*is-active*' => Process::result("active\n"),
        ]);

        $this->postJson('/api/admin/engine/restart', [], $this->admin())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('state', 'active');

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_starts_with($cmd, 'sudo -n systemctl restart sinegualerts-engine');
        });
    }
}
