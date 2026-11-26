<?php

namespace App\Services\Ai\Tools;

use App\Models\AiAccessKey;

/**
 * A toolset that needs to know WHICH key is calling, while staying listed for
 * every key: what it answers depends on the key's switches (People's users
 * tool adds Telegram usernames for a key allowed to see them, 2026-11-26).
 *
 * withKey() is called before EVERY call — with null when no key is asking
 * (the Creatives assistant's query_data) — so one call's key never carries
 * over to the next.
 */
interface KeyScopedToolset extends Toolset
{
    public function withKey(?AiAccessKey $key): void;
}
