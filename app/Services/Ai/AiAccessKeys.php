<?php

namespace App\Services\Ai;

use App\Models\AiAccessKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keys for the read-only AI connector (POST /api/mcp).
 *
 * A key is `pxa_` + 40 random characters. Only its SHA-256 is stored, so a
 * database dump hands nobody a working key, and the admin who made it sees it
 * exactly once. Every key expires (EXPIRY_DAYS), can be revoked in one click,
 * and records when and from where it was last used.
 *
 * The key opens READ tools only — McpServer has no write tool to reach — and
 * it is not a user: it never passes `auth:sanctum`, so no admin route accepts it.
 */
final class AiAccessKeys
{
    public const PREFIX = 'pxa_';

    /** The lifetimes the admin page offers. */
    public const EXPIRY_DAYS = [30, 90, 180, 365];

    public const DEFAULT_EXPIRY_DAYS = 90;

    /** More than this many usable keys at once is refused (MAX_ACTIVE_KEYS). */
    public const MAX_ACTIVE = 5;

    /** last_used_at / last_used_ip are written at most this often per key. */
    private const TOUCH_SECONDS = 60;

    /**
     * @return array{0: AiAccessKey, 1: string} the row and the plain key (shown once)
     */
    public function issue(string $name, int $days, ?string $adminUniId, ?string $adminName): array
    {
        $plain = self::PREFIX.Str::random(40);

        $key = AiAccessKey::create([
            'name' => $name,
            'prefix' => substr($plain, 0, 10),
            'key_hash' => self::hash($plain),
            'created_by' => $adminUniId,
            'created_by_name' => $adminName,
            'expires_at' => now()->addDays($days),
        ]);

        return [$key, $plain];
    }

    public function activeCount(): int
    {
        return AiAccessKey::query()
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();
    }

    /** The usable key this plain value belongs to, or null. */
    public function find(string $plain): ?AiAccessKey
    {
        if (! str_starts_with($plain, self::PREFIX) || strlen($plain) > 100) {
            return null;
        }
        $key = AiAccessKey::where('key_hash', self::hash($plain))->first();

        return $key && $key->isUsable() ? $key : null;
    }

    /** Record use — throttled, best-effort: a failed write never fails a call. */
    public function touch(AiAccessKey $key, ?string $ip): void
    {
        if ($key->last_used_at && $key->last_used_at->diffInSeconds(now()) < self::TOUCH_SECONDS
            && $key->last_used_ip === $ip) {
            return;
        }
        try {
            $key->forceFill(['last_used_at' => now(), 'last_used_ip' => $ip])->save();
        } catch (Throwable $e) {
            Log::warning('ai-access: could not record key use', ['key_id' => $key->id, 'error' => $e->getMessage()]);
        }
    }

    public function revoke(AiAccessKey $key, ?string $adminName): void
    {
        if ($key->revoked_at === null) {
            $key->forceFill(['revoked_at' => now(), 'revoked_by_name' => $adminName])->save();
        }
    }

    /**
     * One row of the question log — best-effort, like touch().
     *
     * @param  array<string, mixed>|null  $arguments
     */
    public function log(?AiAccessKey $key, string $method, ?string $tool, ?array $arguments, bool $ok,
        ?string $error, ?int $bytes, int $durationMs, ?string $ip): void
    {
        try {
            $args = $arguments === null ? null : json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($args !== null && strlen($args) > 2000) {
                $args = json_encode(['_truncated' => substr($args, 0, 1900)]);
            }
            DB::table('ai_access_logs')->insert([
                'key_id' => $key?->id,
                'method' => substr($method, 0, 40),
                'tool' => $tool === null ? null : substr($tool, 0, 60),
                'arguments' => $args,
                'status' => $ok ? 'ok' : 'error',
                'error' => $error === null ? null : substr($error, 0, 255),
                'response_bytes' => $bytes,
                'duration_ms' => $durationMs,
                'ip' => $ip,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('ai-access: could not write the question log', ['error' => $e->getMessage()]);
        }
    }

    /** What the admin page shows for a key. Never the hash. */
    public function present(AiAccessKey $key): array
    {
        return [
            'id' => $key->id,
            'name' => $key->name,
            'prefix' => $key->prefix,
            'state' => $key->state(),
            'created_by_name' => $key->created_by_name,
            'created_at' => $key->created_at?->toIso8601String(),
            'expires_at' => $key->expires_at?->toIso8601String(),
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'last_used_ip' => $key->last_used_ip,
            'revoked_at' => $key->revoked_at?->toIso8601String(),
            'revoked_by_name' => $key->revoked_by_name,
        ];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
