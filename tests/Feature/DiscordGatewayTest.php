<?php

namespace Tests\Feature;

use App\Services\Discord\DiscordGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The HTTP shape of every Discord call, against Http::fake — the only Discord
 * test that touches the HTTP client. (On the WAMP box this file may die with
 * a native access violation like the other Http::fake suites; run it alone.)
 */
class DiscordGatewayTest extends TestCase
{
    private DiscordGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.discord.client_id' => 'cid',
            'services.discord.client_secret' => 'csecret',
            'services.discord.redirect_uris' => ['http://localhost:5173/auth/discord/callback'],
            'services.discord.bot_token' => 'bot-token',
            'services.discord.guild_id' => 'g1',
            'services.discord.user_agent' => 'DiscordBot (https://pixel-alpha.com, 1.0)',
        ]);

        $this->gateway = new DiscordGateway;
    }

    public function test_token_exchange_is_form_encoded_with_client_credentials_as_fields(): void
    {
        Http::fake([
            'discord.com/api/v10/oauth2/token' => Http::response(['access_token' => 'at', 'scope' => 'identify email', 'expires_in' => 604800]),
        ]);

        $token = $this->gateway->exchangeCode('the-code', 'http://localhost:5173/auth/discord/callback');

        $this->assertSame('at', $token['access_token']);
        Http::assertSent(function (Request $request) {
            return $request->url() === DiscordGateway::API_BASE.'/oauth2/token'
                && $request->isForm()
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'the-code'
                && $request['redirect_uri'] === 'http://localhost:5173/auth/discord/callback'
                && $request['client_id'] === 'cid'
                && $request['client_secret'] === 'csecret'
                && $request->header('User-Agent') === ['DiscordBot (https://pixel-alpha.com, 1.0)'];
        });
    }

    public function test_a_refused_exchange_is_null_not_an_exception(): void
    {
        Http::fake(['discord.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
        Log::shouldReceive('warning')->once();

        $this->assertNull($this->gateway->exchangeCode('bad', 'http://localhost:5173/auth/discord/callback'));
    }

    public function test_me_carries_the_bearer_token_and_returns_the_id_as_a_string(): void
    {
        Http::fake([
            'discord.com/api/v10/users/@me' => Http::response([
                'id' => 123456789012345678, 'username' => 'u', 'global_name' => 'U', 'avatar' => null, 'email' => 'u@x.io', 'verified' => true,
            ]),
        ]);

        $me = $this->gateway->me('at');

        $this->assertSame('123456789012345678', $me['id']);
        $this->assertSame('u@x.io', $me['email']);
        $this->assertTrue($me['verified']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer at'));
    }

    public function test_bot_calls_carry_the_bot_header_and_treat_201_and_204_as_success(): void
    {
        Http::fake([
            'discord.com/api/v10/guilds/g1/members/u1' => Http::sequence()
                ->push(['user' => ['id' => 'u1']], 201)
                ->push('', 204),
        ]);

        $this->assertTrue($this->gateway->addGuildMember('u1', 'user-token'));
        $this->assertTrue($this->gateway->addGuildMember('u1', 'user-token'));

        Http::assertSent(function (Request $r) {
            return $r->method() === 'PUT'
                && $r->hasHeader('Authorization', 'Bot bot-token')
                && $r['access_token'] === 'user-token';
        });
    }

    public function test_member_roles_maps_unknown_member_to_null_and_is_quiet_about_it(): void
    {
        Http::fake([
            'discord.com/api/v10/guilds/g1/members/u1' => Http::response(['code' => DiscordGateway::CODE_UNKNOWN_MEMBER, 'message' => 'Unknown Member'], 404),
            'discord.com/api/v10/guilds/g1/members/u2' => Http::response(['roles' => [900, '901']]),
        ]);
        Log::shouldReceive('warning')->never();

        $this->assertNull($this->gateway->memberRoles('u1'));
        $this->assertSame(['900', '901'], $this->gateway->memberRoles('u2'));
    }

    public function test_role_edits_are_put_and_delete_and_a_403_is_false_with_a_warning(): void
    {
        Http::fake([
            'discord.com/api/v10/guilds/g1/members/u1/roles/r1' => Http::response('', 204),
            'discord.com/api/v10/guilds/g1/members/u1/roles/r2' => Http::response(['code' => 50013, 'message' => 'Missing Permissions'], 403),
        ]);
        Log::shouldReceive('warning')->once()->withArgs(fn ($msg, $ctx) => $ctx['code'] === 50013);

        $this->assertTrue($this->gateway->addRole('u1', 'r1'));
        $this->assertTrue($this->gateway->removeRole('u1', 'r1'));
        $this->assertFalse($this->gateway->addRole('u1', 'r2'));

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/roles/r1'));
    }

    public function test_a_429_is_retried_once_after_retry_after(): void
    {
        Http::fake([
            'discord.com/api/v10/guilds/g1/members/u1/roles/r1' => Http::sequence()
                ->push(['retry_after' => 0.2, 'message' => 'rate limited'], 429)
                ->push('', 204),
        ]);

        $this->assertTrue($this->gateway->addRole('u1', 'r1'));
        Http::assertSentCount(2);
    }

    public function test_an_unreachable_discord_is_false_or_null_never_a_throw(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // A faked ConnectionException kills the WAMP PHP build outright
            // (exit -1073741819, same as EngineCacheInvalidationTest's dead-
            // engine case) — and a crash takes the whole file's results with
            // it. Runs on any other OS.
            $this->markTestSkipped('Faked ConnectionException crashes PHP natively on this Windows build.');
        }

        Http::fake(['discord.com/*' => Http::failedConnection('connection refused')]);
        Log::shouldReceive('warning')->atLeast()->once();

        $this->assertNull($this->gateway->exchangeCode('c', 'http://localhost:5173/auth/discord/callback'));
        $this->assertNull($this->gateway->me('at'));
        $this->assertFalse($this->gateway->addGuildMember('u1', 'at'));
        $this->assertNull($this->gateway->memberRoles('u1'));
        $this->assertFalse($this->gateway->addRole('u1', 'r1'));
    }

    public function test_bot_calls_are_skipped_entirely_without_bot_config(): void
    {
        config(['services.discord.bot_token' => null]);
        Http::fake();

        $this->assertFalse($this->gateway->addGuildMember('u1', 'at'));
        $this->assertNull($this->gateway->memberRoles('u1'));
        $this->assertFalse($this->gateway->addRole('u1', 'r1'));
        Http::assertNothingSent();
    }

    public function test_diagnostics_are_presence_booleans_only(): void
    {
        $diag = $this->gateway->diagnostics();

        $this->assertTrue($diag['client_secret_set']);
        $this->assertTrue($diag['bot_token_set']);
        $this->assertStringNotContainsString('csecret', json_encode($diag));
        $this->assertStringNotContainsString('bot-token', json_encode($diag));
    }

    public function test_authorize_url_has_the_public_bits_only(): void
    {
        $url = $this->gateway->authorizeUrl();

        $this->assertStringStartsWith(DiscordGateway::AUTHORIZE_URL.'?', $url);
        $this->assertStringContainsString('client_id=cid', $url);
        $this->assertStringContainsString('scope=identify+email+guilds.join', $url);
        $this->assertStringNotContainsString('csecret', $url);
        $this->assertStringNotContainsString('redirect_uri', $url);
    }
}
