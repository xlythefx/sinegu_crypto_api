<?php

namespace App\Services\Ai\Tools;

use App\Models\AiAccessKey;

/**
 * A toolset that needs to know WHICH key is calling — the only kind that may
 * write (Creatives, 2026-11-17). Its tools are listed and run only for a key
 * {@see self::allows()} accepts; every other key never sees them.
 */
interface KeyAwareToolset extends KeyScopedToolset
{
    /** May this key see and use these tools at all? */
    public function allows(?AiAccessKey $key): bool;
}
