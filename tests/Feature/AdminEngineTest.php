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

    /* ============ recap previews ============ */

    public function test_recap_preview_is_forwarded_to_the_engine_with_the_admin_secret(): void
    {
        config(['services.engine.webhook_secrets.binance' => 'engine-admin-secret']);
        Http::fake([
            '127.0.0.1:5010/admin/reports/preview' => Http::response([
                'success' => true,
                'kind' => 'daily',
                'on' => '2026-09-20',
                'telegram' => true,
                'messages' => [[
                    'html' => "📅 <b>Daily Report — 20 Sep 2026 · Binance</b>\n\nReturn: <b>+2.820%</b>",
                    'text' => "📅 Daily Report — 20 Sep 2026 · Binance\n\nReturn: +2.820%",
                ]],
            ]),
        ]);

        $this->postJson('/api/admin/engine/reports/preview', ['kind' => 'daily', 'on' => '2026-09-20'], $this->admin())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('kind', 'daily')
            ->assertJsonPath('on', '2026-09-20')
            ->assertJsonPath('telegram', true)
            ->assertJsonPath('messages.0.text', "📅 Daily Report — 20 Sep 2026 · Binance\n\nReturn: +2.820%");

        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:5010/admin/reports/preview'
            && $request->hasHeader('X-Admin-Secret', 'engine-admin-secret')
            && $request['kind'] === 'daily'
            && $request['on'] === '2026-09-20');
    }

    public function test_recap_preview_rejects_a_bad_kind_or_day_before_calling_the_engine(): void
    {
        Http::fake();

        $this->postJson('/api/admin/engine/reports/preview', ['kind' => 'hourly'], $this->admin())
            ->assertStatus(422);
        $this->postJson('/api/admin/engine/reports/preview', ['kind' => 'daily', 'on' => 'last tuesday'], $this->admin())
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_recap_preview_without_an_engine_secret_is_503_not_a_leak(): void
    {
        config(['services.engine.webhook_secrets.binance' => '']);
        Http::fake();

        $this->postJson('/api/admin/engine/reports/preview', ['kind' => 'daily'], $this->admin())
            ->assertStatus(503)
            ->assertJsonPath('message', 'No engine secret is configured on this server.');

        Http::assertNothingSent();
    }

    public function test_recap_preview_reports_an_unreachable_engine_as_503(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // The faked ConnectionException path crashes PHP natively (0xC0000005)
            // on the WAMP dev box, like the other outbound-HTTP tests; CI runs it.
            $this->markTestSkipped('ConnectionException fake crashes PHP on Windows/WAMP.');
        }
        config(['services.engine.webhook_secrets.binance' => 'engine-admin-secret']);
        Http::fake(['127.0.0.1:5010/*' => Http::failedConnection('connection refused')]);

        $this->postJson('/api/admin/engine/reports/preview', ['kind' => 'weekly'], $this->admin())
            ->assertStatus(503)
            ->assertJsonPath('message', 'Could not reach the engine.');
    }

    public function test_recap_preview_relays_the_engines_refusal(): void
    {
        config(['services.engine.webhook_secrets.binance' => 'engine-admin-secret']);
        Http::fake([
            '127.0.0.1:5010/admin/reports/preview' => Http::response(['error' => 'weekly recap is disabled'], 422),
        ]);

        $this->postJson('/api/admin/engine/reports/preview', ['kind' => 'weekly'], $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('message', 'weekly recap is disabled');
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
