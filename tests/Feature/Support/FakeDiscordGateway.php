<?php

namespace Tests\Feature\Support;

use App\Services\Discord\DiscordGateway;

/**
 * An in-memory Discord for the Feature tests: bind it over DiscordGateway and
 * every controller / service talks to this instead of the network. Records
 * each call so a test can assert what the app asked Discord to do.
 *
 * Config-derived answers (configured(), botConfigured(), redirect allow-list,
 * authorize URL) are inherited on purpose — the tests set the config and
 * expect the real gating logic to run against it.
 */
class FakeDiscordGateway extends DiscordGateway
{
    /** Every call, in order: [method, ...args]. */
    public array $calls = [];

    /** The user /users/@me will describe. */
    public array $profile = [
        'id' => '123456789012345678',
        'username' => 'pixeltrader',
        'global_name' => 'Pixel Trader',
        'avatar' => 'abc123',
        'email' => 'trader@example.com',
        'verified' => true,
    ];

    /** discord user id => role ids, for users in the server. */
    public array $members = [];

    /** Simulate "Discord is unreachable" — every call fails softly. */
    public bool $down = false;

    /** Simulate Discord refusing the authorization code. */
    public bool $refuseCode = false;

    public string $accessToken = 'fake-user-access-token';

    public function exchangeCode(string $code, string $redirectUri): ?array
    {
        $this->calls[] = ['exchangeCode', $code, $redirectUri];
        if ($this->down || $this->refuseCode) {
            return null;
        }

        return ['access_token' => $this->accessToken, 'scope' => self::SCOPES, 'expires_in' => 604800];
    }

    public function me(string $accessToken): ?array
    {
        $this->calls[] = ['me', $accessToken];
        if ($this->down || $accessToken !== $this->accessToken) {
            return null;
        }

        return $this->profile;
    }

    public function addGuildMember(string $userId, string $accessToken): bool
    {
        $this->calls[] = ['addGuildMember', $userId, $accessToken];
        if ($this->down || ! $this->botConfigured()) {
            return false;
        }
        $this->members[$userId] ??= [];

        return true;
    }

    public function memberRoles(string $userId): ?array
    {
        $this->calls[] = ['memberRoles', $userId];
        if ($this->down || ! $this->botConfigured()) {
            return null;
        }

        return $this->members[$userId] ?? null;
    }

    public function addRole(string $userId, string $roleId): bool
    {
        $this->calls[] = ['addRole', $userId, $roleId];
        if ($this->down || ! isset($this->members[$userId])) {
            return false;
        }
        if (! in_array($roleId, $this->members[$userId], true)) {
            $this->members[$userId][] = $roleId;
        }

        return true;
    }

    public function removeRole(string $userId, string $roleId): bool
    {
        $this->calls[] = ['removeRole', $userId, $roleId];
        if ($this->down || ! isset($this->members[$userId])) {
            return false;
        }
        $this->members[$userId] = array_values(array_diff($this->members[$userId], [$roleId]));

        return true;
    }

    /** The recorded calls of one kind. */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c[0] === $method));
    }

    /** Roles the fake server currently shows for a user (null = not a member). */
    public function rolesOf(string $userId): ?array
    {
        return $this->members[$userId] ?? null;
    }
}
