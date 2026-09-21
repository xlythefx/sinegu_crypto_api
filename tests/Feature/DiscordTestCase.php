<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use App\Services\Discord\DiscordGateway;
use Tests\Feature\Support\FakeDiscordGateway;

/**
 * Shared plumbing for the Discord tests: a fully configured app + bot in
 * config, the in-memory Discord bound over the gateway, and a bearer helper.
 */
abstract class DiscordTestCase extends EngineTestCase
{
    protected const CLIENT_SECRET = 'test-client-secret-do-not-leak';

    protected const BOT_TOKEN = 'test-bot-token-do-not-leak';

    protected const ROLE_MEMBER = '900000000000000001';

    protected const ROLE_TRADER = '900000000000000002';

    protected const REDIRECT = 'http://localhost:5173/auth/discord/callback';

    protected FakeDiscordGateway $discord;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.discord.client_id' => '111111111111111111',
            'services.discord.client_secret' => self::CLIENT_SECRET,
            'services.discord.redirect_uris' => [self::REDIRECT, 'https://pixel-alpha.com/auth/discord/callback'],
            'services.discord.login_public' => true,
            'services.discord.bot_token' => self::BOT_TOKEN,
            'services.discord.guild_id' => '800000000000000000',
            'services.discord.role_member_id' => self::ROLE_MEMBER,
            'services.discord.role_trader_id' => self::ROLE_TRADER,
        ]);

        $this->discord = new FakeDiscordGateway;
        $this->app->instance(DiscordGateway::class, $this->discord);
    }

    /** Sanctum bearer header for a seeded user (see PaymentTestCase::userHeaders for why guards are forgotten). */
    protected function userHeaders(string $uniId): array
    {
        $user = UserCredential::where('uni_id', $uniId)->firstOrFail();

        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** POST the OAuth callback as the SPA would, for the fake's current profile. */
    protected function oauthCallback(string $code = 'good-code', string $redirect = self::REDIRECT)
    {
        return $this->postJson('/api/auth/discord/callback', [
            'code' => $code,
            'redirect_uri' => $redirect,
        ]);
    }

    /** A user already linked to the fake's Discord id. */
    protected function makeLinkedUser(array $overrides = []): string
    {
        return $this->makeUser(array_merge([
            'discord_id' => $this->discord->profile['id'],
            'discord_username' => $this->discord->profile['username'],
            'discord_linked_at' => now(),
        ], $overrides));
    }
}
