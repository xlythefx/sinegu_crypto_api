<?php

namespace Tests\Feature;

use App\Models\AiAccessKey;
use App\Models\Invoice;
use App\Models\TradeLog;
use App\Models\UserCredential;
use App\Services\Ai\AiAccessKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * The read-only AI connector (POST /api/mcp) and Admin → AI Access.
 *
 * Three promises are pinned here: only a live key gets in (and never through
 * the URL); every tool answers from the same data the admin pages show; and
 * no answer ever carries a credential or contact detail — exchange keys,
 * secrets, password hashes, emails — whatever the tool.
 */
class AiAccessTest extends EngineTestCase
{
    private const SECRET_KEY = 'SEEKRIT-secret-key-value-123';

    private string $plain;

    private string $master;

    private string $customer;

    private string $customerApiKey;

    private string $customerEmail;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->plain] = app(AiAccessKeys::class)->issue('Owner Grok', 90, null, 'Test Admin');
    }

    private function mcp(array $message, ?string $key = null, string $uri = '/api/mcp'): TestResponse
    {
        return $this->postJson($uri, $message, $key === '' ? [] : ['X-Api-Key' => $key ?? $this->plain]);
    }

    private function tool(string $tool, array $arguments = []): array
    {
        $res = $this->mcp(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);
        $res->assertOk();
        $result = $res->json('result');
        $this->assertFalse($result['isError'], "{$tool} failed: ".($result['content'][0]['text'] ?? ''));

        return json_decode($result['content'][0]['text'], true);
    }

    private function adminHeaders(string $type = 'admin'): array
    {
        $uni = $this->makeUser(['type' => $type, 'name' => 'Staff '.$type]);

        return ['Authorization' => 'Bearer '.UserCredential::find($uni)->createToken('spa')->plainTextToken];
    }

    /** A master and a customer on Binance with signals, trades, an invoice and an asset. */
    private function seedPlatform(): void
    {
        $this->master = $this->makeUser(['type' => 'master', 'name' => 'House Master']);
        $this->customer = $this->makeUser(['name' => 'Juan Dela Cruz']);
        $this->customerEmail = DB::table('user_credentials')->where('uni_id', $this->customer)->value('email');

        $masterAcct = $this->makeAccount($this->master, ['name' => 'master main', 'secret_key' => self::SECRET_KEY]);
        $custAcct = $this->makeAccount($this->customer, ['name' => 'Juan Futures', 'balance' => 2500, 'initial_deposit' => 2000, 'secret_key' => self::SECRET_KEY]);
        $masterKey = DB::table('binance_accounts')->find($masterAcct)->api_key;
        $this->customerApiKey = DB::table('binance_accounts')->find($custAcct)->api_key;

        DB::table('binance_transactions')->insert([
            'api_key' => $this->customerApiKey, 'uni_id' => $this->customer, 'type' => 'DEPOSIT', 'amount' => 500,
            'currency' => 'USDT', 'created_at' => now()->subDays(5),
        ]);

        $detail = fn (string $uni, string $acct, string $status, ?string $reason = null) => array_filter([
            'account' => $acct, 'uni_id' => $uni, 'status' => $status, 'reason' => $reason,
            'is_master' => $uni === $this->master, 'quantity' => 1.5,
        ], fn ($v) => $v !== null);

        foreach ([['BUY', 'signal', 2], ['BUY', 'signal', 1], ['EXIT_LONG', 'signal', 0], ['BUY', 'retry', 0]] as $i => [$action, $category, $daysAgo]) {
            TradeLog::create([
                'exchange' => 'binance', 'feed' => 'main', 'action' => $action, 'ticker' => 'LTCUSDT', 'success' => true,
                'price' => 90, 'category' => $category, 'target_count' => 2, 'filled' => 2, 'skipped' => 0, 'failed' => 0,
                'ts' => now()->subDays($daysAgo)->subMinutes($i)->toDateTimeString(),
                'details' => [$detail($this->master, 'master main', 'filled'), $detail($this->customer, 'Juan Futures', 'filled')],
            ]);
        }
        TradeLog::create([
            'exchange' => 'binance', 'feed' => 'main', 'action' => 'SELL', 'ticker' => 'BTCUSDT', 'success' => true,
            'price' => 60000, 'category' => 'signal', 'target_count' => 2, 'filled' => 1, 'skipped' => 1, 'failed' => 0,
            'ts' => now()->subDay()->toDateTimeString(),
            'details' => [$detail($this->master, 'master main', 'filled'), $detail($this->customer, 'Juan Futures', 'skipped', 'maxed sizing')],
        ]);

        $trade = fn (string $key, string $uni, string $symbol, float $pnl, int $daysAgo) => DB::table('binance_pastpositions')->insert([
            'api_key' => $key, 'uni_id' => $uni, 'symbol' => $symbol, 'position_side' => 'LONG', 'position_amt' => 1.5,
            'increments_closed' => 1, 'entry_price' => 90, 'exit_price' => 95, 'realized_pnl' => $pnl, 'exchange_fee' => 0.14,
            'fee_source' => 'actual', 'side' => 'SELL', 'order_id' => 1, 'closed_at' => now()->subDays($daysAgo)->toDateTimeString(),
            'close_source' => 'bot', 'is_sandbox' => 0, 'created_at' => now(),
        ]);
        $trade($masterKey, $this->master, 'LTCUSDT', 12.5, 1);
        $trade($this->customerApiKey, $this->customer, 'LTCUSDT', 30.0, 1);
        $trade($masterKey, $this->master, 'BTCUSDT', -8.0, 2);
        // Invoice-scenario scratch rows are never business.
        $trade('SBXINV-scratch', $this->customer, 'LTCUSDT', 9999.0, 1);

        Invoice::create([
            'user_id' => $this->customer, 'account_id' => $custAcct, 'exchange' => 'binance', 'api_key' => $this->customerApiKey,
            'month_year' => now()->subMonth()->format('Y-m'), 'total_fee' => 42.5, 'status' => 'pending',
            'due_date' => now()->subDays(3)->toDateString(),
        ]);

        DB::table('assets')->insert([
            'ticker' => 'LTCUSDT', 'type' => 'Cryptocurrency', 'broker' => 'Binance', 'side' => 'ALL',
            'max_increments' => 42, 'base_size' => 14, 'enabled' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // --- The gate ---------------------------------------------------------

    public function test_a_call_without_a_key_is_refused(): void
    {
        $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], '')
            ->assertStatus(401)
            ->assertJsonPath('error.data.error_code', 'KEY_MISSING');
    }

    public function test_a_wrong_key_is_refused(): void
    {
        $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], AiAccessKeys::PREFIX.str_repeat('x', 40))
            ->assertStatus(401)
            ->assertJsonPath('error.data.error_code', 'KEY_INVALID');
    }

    public function test_a_key_in_the_url_is_refused_even_when_it_is_valid(): void
    {
        $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], '', '/api/mcp?key='.$this->plain)
            ->assertStatus(400)
            ->assertJsonPath('error.data.error_code', 'KEY_IN_URL');
    }

    public function test_the_bearer_form_works_too(): void
    {
        $this->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Authorization' => 'Bearer '.$this->plain])
            ->assertOk()
            ->assertJsonPath('id', 1);
    }

    public function test_revoked_and_expired_keys_stop_working(): void
    {
        [$revoked, $revokedPlain] = app(AiAccessKeys::class)->issue('Old', 30, null, null);
        app(AiAccessKeys::class)->revoke($revoked, 'Test Admin');
        $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $revokedPlain)->assertStatus(401);

        [$expired, $expiredPlain] = app(AiAccessKeys::class)->issue('Expired', 30, null, null);
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $expiredPlain)->assertStatus(401);
    }

    public function test_only_post_is_served(): void
    {
        $this->get('/api/mcp', ['X-Api-Key' => $this->plain])->assertStatus(405);
    }

    /** The per-key limit runs AFTER the key check, so it counts per key, not per IP. */
    public function test_a_key_is_rate_limited_per_minute(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->mcp(['jsonrpc' => '2.0', 'id' => $i, 'method' => 'ping'])->assertOk();
        }
        $this->mcp(['jsonrpc' => '2.0', 'id' => 61, 'method' => 'ping'])->assertStatus(429);

        // Another key from the same address is not held back by the first one's count.
        [, $other] = app(AiAccessKeys::class)->issue('Second', 30, null, null);
        $this->mcp(['jsonrpc' => '2.0', 'id' => 62, 'method' => 'ping'], $other)->assertOk();
    }

    public function test_the_plain_key_is_never_stored(): void
    {
        $this->assertSame(0, DB::table('ai_access_keys')->where('key_hash', $this->plain)->count());
        $this->assertSame(1, DB::table('ai_access_keys')->where('key_hash', hash('sha256', $this->plain))->count());
        $this->assertStringNotContainsString($this->plain, json_encode(DB::table('ai_access_keys')->get()));
    }

    // --- The protocol -----------------------------------------------------

    public function test_initialize_answers_the_handshake_with_instructions(): void
    {
        $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'grok', 'version' => '1'],
        ]])
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.serverInfo.name', 'pixel-alpha')
            ->assertJsonPath('result.capabilities.tools.listChanged', false)
            ->assertJsonPath('result.instructions', fn ($v) => str_contains($v, 'READ-ONLY'));
    }

    public function test_an_unknown_protocol_version_gets_the_fallback(): void
    {
        $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']])
            ->assertJsonPath('result.protocolVersion', '2025-06-18');
    }

    public function test_a_notification_is_accepted_without_a_body(): void
    {
        $res = $this->mcp(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $res->assertStatus(202);
        $this->assertSame('', $res->getContent());
    }

    public function test_every_tool_is_listed_and_read_only(): void
    {
        $tools = $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk()->json('result.tools');
        $names = array_column($tools, 'name');

        foreach (['guide', 'search_signals', 'get_signal', 'signal_counts', 'signal_health', 'open_positions', 'closed_trades',
            'asset_performance', 'assets', 'asset_changes', 'accounts', 'users', 'invoices', 'platform_summary', 'business_stats'] as $expected) {
            $this->assertContains($expected, $names);
        }
        foreach ($tools as $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint'], $tool['name']);
            $this->assertSame('object', $tool['inputSchema']['type'], $tool['name']);
        }
    }

    public function test_an_unknown_tool_or_method_is_a_jsonrpc_error(): void
    {
        $this->mcp(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'delete_everything']])
            ->assertJsonPath('error.code', -32602);
        $this->mcp(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'resources/list'])
            ->assertJsonPath('error.code', -32601);
    }

    public function test_bad_arguments_come_back_as_a_tool_error_the_ai_can_fix(): void
    {
        $res = $this->mcp(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => [
            'name' => 'signal_counts', 'arguments' => ['from' => 'last tuesday'],
        ]])->assertOk();

        $this->assertTrue($res->json('result.isError'));
        $this->assertStringContainsString('YYYY-MM-DD', $res->json('result.content.0.text'));
    }

    // --- The tools --------------------------------------------------------

    public function test_signal_counts_answer_how_many_ltc_signals_came_in(): void
    {
        $this->seedPlatform();

        $out = $this->tool('signal_counts', ['coin' => 'ltc']);

        $this->assertSame('LTC', $out['window']['coin']);
        $this->assertSame(3, $out['totals']['signals'], 'the retry is not a new signal');
        $this->assertSame(2, $out['totals']['buys']);
        $this->assertSame(1, $out['totals']['exits']);
        $this->assertSame(1, $out['totals']['retries']);

        $byCoin = $this->tool('signal_counts');
        $this->assertSame(['LTC', 'BTC'], array_column($byCoin['groups'], 'coin'));
    }

    public function test_signals_can_be_searched_with_each_accounts_result_and_opened(): void
    {
        $this->seedPlatform();

        $out = $this->tool('search_signals', ['coin' => 'BTC', 'include_accounts' => true]);
        $this->assertCount(1, $out['signals']);
        $accounts = $out['signals'][0]['accounts'];
        $this->assertSame('Juan Dela Cruz', $accounts[1]['owner']);
        $this->assertSame('skipped', $accounts[1]['status']);
        $this->assertNotEmpty($accounts[1]['reason']);

        $one = $this->tool('get_signal', ['id' => $out['signals'][0]['id']]);
        $this->assertSame('BTC', $one['signal']['coin']);
        $this->assertCount(2, $one['signal']['details']);
    }

    public function test_asset_performance_says_which_coins_are_up(): void
    {
        $this->seedPlatform();

        $out = $this->tool('asset_performance');

        $this->assertSame(['LTC'], $out['up']);
        $this->assertSame(['BTC'], $out['down']);
        $ltc = $out['coins'][0];
        $this->assertSame(42.5, $ltc['everyone']['pnl_after_fees'], 'the SBXINV- scratch row is not business');
        $this->assertSame(12.5, $ltc['master']['pnl_after_fees']);
        $this->assertSame(30.0, $ltc['customers']['pnl_after_fees']);
        $this->assertTrue($ltc['trading_now'][0]['switched_on']);
    }

    public function test_closed_trades_total_per_person(): void
    {
        $this->seedPlatform();

        $out = $this->tool('closed_trades', ['group_by' => 'owner']);

        $this->assertSame(3, $out['totals']['trades']);
        $this->assertSame(34.5, $out['totals']['pnl_after_fees']);
        $this->assertSame('Juan Dela Cruz (customer)', $out['groups'][0]['owner']);

        $listed = $this->tool('closed_trades', ['owner' => 'juan']);
        $this->assertSame(1, $listed['total_rows']);
        $this->assertSame('Juan Futures', $listed['trades'][0]['account']);
    }

    public function test_invoices_name_who_is_overdue(): void
    {
        $this->seedPlatform();

        $out = $this->tool('invoices', ['status' => 'overdue']);

        $this->assertSame(1, $out['matching']);
        $this->assertSame('Juan Dela Cruz', $out['invoices'][0]['owner']);
        $this->assertSame('overdue', $out['invoices'][0]['state']);
        $this->assertSame(3, $out['invoices'][0]['days_overdue']);
        $this->assertSame(42.5, $out['summary']['overdue']['amount']);
    }

    /** Ids repeat across {x}_accounts: a MEXC invoice must name its MEXC account, not Binance #same-id. */
    public function test_an_invoice_names_the_account_on_its_own_exchange(): void
    {
        $user = $this->makeUser(['name' => 'Maria Santos']);
        $binanceId = $this->makeAccount($user, ['name' => 'Wrong Binance Row']);
        $mexcId = $this->makeAccount($user, ['name' => 'Maria MEXC', 'enabled' => 0], 'mexc');
        DB::table('mexc_accounts')->where('id', $mexcId)->update(['id' => $binanceId]);

        Invoice::create([
            'user_id' => $user, 'account_id' => $binanceId, 'exchange' => 'mexc', 'api_key' => 'mexc-key',
            'month_year' => '2026-10', 'total_fee' => 10, 'status' => 'pending', 'due_date' => now()->subDay()->toDateString(),
        ]);

        $row = $this->tool('invoices', ['exchange' => 'mexc'])['invoices'][0];
        $this->assertSame('Maria MEXC', $row['account_name']);
        $this->assertTrue($row['account_paused']);
    }

    public function test_accounts_show_balance_and_money_deposited(): void
    {
        $this->seedPlatform();

        $out = $this->tool('accounts', ['owner' => 'juan']);

        $this->assertSame(1, $out['count']);
        $row = $out['accounts'][0];
        $this->assertSame(2500.0, $row['balance']);
        $this->assertSame(2500.0, $row['total_deposited']);
        $this->assertSame(30.0, $row['pnl_all_time']);
    }

    /**
     * Every tool, against a seeded platform: each answers, and no answer
     * carries an exchange key, a secret, an email or a password hash.
     */
    public function test_no_tool_ever_returns_a_credential_or_an_email(): void
    {
        $this->seedPlatform();
        $passwordHash = DB::table('user_credentials')->where('uni_id', $this->customer)->value('password');
        // How to reach the customer is personal data too (2026-11-16).
        DB::table('user_contact_handles')->updateOrInsert(
            ['uni_id' => $this->customer],
            ['telegram' => 'juan_tg_handle', 'whatsapp' => '+639171234567', 'created_at' => now(), 'updated_at' => now()],
        );

        $calls = [
            'guide' => [], 'search_signals' => ['include_accounts' => true], 'signal_counts' => [], 'signal_health' => [],
            'open_positions' => [], 'closed_trades' => [], 'asset_performance' => [], 'assets' => [], 'asset_changes' => [],
            'accounts' => ['status' => 'all'], 'users' => [], 'invoices' => ['status' => 'all'], 'platform_summary' => [],
            'business_stats' => [],
        ];
        $firstSignal = TradeLog::query()->min('id');
        $calls['get_signal'] = ['id' => $firstSignal];

        foreach ($calls as $tool => $arguments) {
            $text = json_encode($this->tool($tool, $arguments));
            foreach ([$this->customerApiKey, self::SECRET_KEY, $this->customerEmail, $passwordHash, $this->plain, '+639171234567', 'juan_tg_handle'] as $secret) {
                $this->assertStringNotContainsString($secret, $text, "{$tool} leaked a credential");
            }
            $this->assertDoesNotMatchRegularExpression('/"(api_key|secret_key|email|password)"/', $text, "{$tool} carries a forbidden field");
        }
    }

    public function test_every_call_is_logged_against_its_key(): void
    {
        $this->tool('guide');

        $row = DB::table('ai_access_logs')->where('method', 'tools/call')->first();
        $this->assertSame('guide', $row->tool);
        $this->assertSame('ok', $row->status);
        $this->assertNotNull(AiAccessKey::first()->last_used_at);
    }

    // --- Admin → AI Access ------------------------------------------------

    public function test_an_admin_makes_a_key_that_is_shown_once_and_works(): void
    {
        $headers = $this->adminHeaders();

        $made = $this->postJson('/api/admin/ai-access/keys', ['name' => 'Boss Grok', 'expires_in_days' => 30], $headers)
            ->assertCreated()
            ->json();
        $plain = $made['plain_key'];
        $this->assertStringStartsWith(AiAccessKeys::PREFIX, $plain);
        $this->assertSame('active', $made['key']['state']);

        $index = $this->getJson('/api/admin/ai-access', $headers)->assertOk();
        $this->assertStringNotContainsString($plain, $index->getContent());
        $this->assertStringNotContainsString('key_hash', $index->getContent());
        $this->assertStringEndsWith('/api/mcp', $index->json('connector.server_url'));
        $this->assertSame('X-Api-Key', $index->json('connector.header_name'));

        $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $plain)->assertOk();

        $this->deleteJson('/api/admin/ai-access/keys/'.$made['key']['id'], [], $headers)
            ->assertOk()
            ->assertJsonPath('key.state', 'revoked');
        $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $plain)->assertStatus(401);

        $this->getJson('/api/admin/ai-access/log', $headers)
            ->assertOk()
            ->assertJsonPath('entries.0.key_name', 'Boss Grok');
    }

    public function test_a_key_can_be_made_that_never_expires_and_is_still_revocable(): void
    {
        $headers = $this->adminHeaders();

        $made = $this->postJson('/api/admin/ai-access/keys', ['name' => 'Boss Grok', 'never_expires' => true], $headers)
            ->assertCreated()
            ->assertJsonPath('key.expires_at', null)
            ->assertJsonPath('key.state', 'active')
            ->json();
        $plain = $made['plain_key'];

        // Years later it still works — nothing but a revoke stops it.
        $this->travel(5 * 365)->days();
        $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $plain)->assertOk();

        // A fresh sign-in: the admin's own session token expired long ago.
        $later = ['Authorization' => 'Bearer '.UserCredential::where('name', 'Staff admin')->first()->createToken('spa')->plainTextToken];
        $this->deleteJson('/api/admin/ai-access/keys/'.$made['key']['id'], [], $later)
            ->assertOk()
            ->assertJsonPath('key.state', 'revoked');
        $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $plain)->assertStatus(401);
    }

    public function test_a_key_with_no_lifetime_given_gets_the_default_not_forever(): void
    {
        $made = $this->postJson('/api/admin/ai-access/keys', ['name' => 'Default'], $this->adminHeaders())
            ->assertCreated()
            ->json();

        $this->assertNotNull($made['key']['expires_at']);
        $this->assertSame(
            now()->addDays(AiAccessKeys::DEFAULT_EXPIRY_DAYS)->toDateString(),
            substr($made['key']['expires_at'], 0, 10),
        );
    }

    public function test_only_full_admins_manage_keys(): void
    {
        $this->getJson('/api/admin/ai-access', $this->adminHeaders('collaborator'))->assertForbidden();
        $this->postJson('/api/admin/ai-access/keys', ['name' => 'x'], $this->adminHeaders('user'))->assertForbidden();
    }

    public function test_active_keys_are_capped(): void
    {
        for ($i = 1; $i < AiAccessKeys::MAX_ACTIVE; $i++) {
            app(AiAccessKeys::class)->issue("Key {$i}", 30, null, null);
        }

        $this->postJson('/api/admin/ai-access/keys', ['name' => 'One too many'], $this->adminHeaders())
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'MAX_ACTIVE_KEYS');
    }
}
