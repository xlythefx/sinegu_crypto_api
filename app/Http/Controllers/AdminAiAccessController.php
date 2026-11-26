<?php

namespace App\Http\Controllers;

use App\Models\AiAccessKey;
use App\Models\CreativeGeneration;
use App\Services\Ai\AiAccessKeys;
use App\Services\Ai\AiTools;
use App\Services\Ai\Tools\CreativeMakeTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin → AI Access: the keys the employer's Grok connector uses
 * (POST /api/mcp), and the log of every question it asked.
 *
 * Full admins only (`admin` group): a key reads everything an admin can read,
 * billing included. The plain key is in the store() response and nowhere
 * else, ever — not in index(), not in the database.
 */
class AdminAiAccessController extends Controller
{
    public function __construct(private AiAccessKeys $keys) {}

    /** GET /api/admin/ai-access — keys, the connector's settings, the tools. */
    public function index(Request $request, AiTools $tools): JsonResponse
    {
        return response()->json([
            'success' => true,
            'keys' => AiAccessKey::orderByDesc('id')->get()->map(fn (AiAccessKey $k) => $this->keys->present($k))->values(),
            'connector' => [
                'server_url' => $this->serverUrl($request),
                'type' => 'Remote HTTPS',
                'header_name' => 'X-Api-Key',
            ],
            'expiry_days' => AiAccessKeys::EXPIRY_DAYS,
            'default_expiry_days' => AiAccessKeys::DEFAULT_EXPIRY_DAYS,
            'max_active_keys' => AiAccessKeys::MAX_ACTIVE,
            'tools' => array_map(fn (array $t) => [
                'name' => $t['name'],
                'title' => $t['title'],
                'description' => $t['description'],
            ], $tools->list()),
            // What a key switched on for Creatives may also do, and its limits.
            'create' => [
                'tools' => collect((new CreativeMakeTools)->definitions())
                    ->map(fn (array $d, string $name) => ['name' => $name, 'title' => $d['title'], 'description' => $d['description']])
                    ->values(),
                'max_per_generation_usd' => CreativeMakeTools::MAX_PER_GENERATION_USD,
                'max_per_key_day_usd' => CreativeMakeTools::MAX_PER_KEY_DAY_USD,
                'label' => CreativeMakeTools::LABEL,
                // Per key: what its Grok generations cost today (UTC) and this month.
                'spent' => CreativeGeneration::query()->toBase()
                    ->whereNotNull('ai_access_key_id')
                    ->where('created_at', '>=', now()->utc()->startOfMonth()->toDateTimeString())
                    ->selectRaw('ai_access_key_id, SUM(charged_usd) AS month_usd, SUM(CASE WHEN created_at >= ? THEN charged_usd ELSE 0 END) AS today_usd, COUNT(*) AS n', [now()->utc()->startOfDay()->toDateTimeString()])
                    ->groupBy('ai_access_key_id')
                    ->get()
                    ->map(fn ($r) => [
                        'key_id' => (int) $r->ai_access_key_id,
                        'today_usd' => round((float) $r->today_usd, 4),
                        'month_usd' => round((float) $r->month_usd, 4),
                        'generations' => (int) $r->n,
                    ])->values(),
            ],
        ]);
    }

    /**
     * POST /api/admin/ai-access/keys {name, expires_in_days | never_expires: true}
     * — the key, shown once. `never_expires` must be sent explicitly: a missing
     * lifetime still means the default, never "forever" by accident.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'expires_in_days' => ['nullable', 'integer', Rule::in(AiAccessKeys::EXPIRY_DAYS)],
            'never_expires' => ['nullable', 'boolean'],
        ]);
        if ($this->keys->activeCount() >= AiAccessKeys::MAX_ACTIVE) {
            return response()->json([
                'success' => false,
                'error_code' => 'MAX_ACTIVE_KEYS',
                'message' => 'There are already '.AiAccessKeys::MAX_ACTIVE.' active keys. Revoke one first.',
            ], 422);
        }

        $admin = $request->user();
        [$key, $plain] = $this->keys->issue(
            trim($data['name']),
            ! empty($data['never_expires']) ? null : (int) ($data['expires_in_days'] ?? AiAccessKeys::DEFAULT_EXPIRY_DAYS),
            $admin?->uni_id,
            $admin?->name,
        );

        return response()->json([
            'success' => true,
            'key' => $this->keys->present($key),
            // The only time this value is ever sent anywhere.
            'plain_key' => $plain,
        ], 201);
    }

    /** DELETE /api/admin/ai-access/keys/{id} — revoke now. The row stays for the log. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $key = AiAccessKey::find($id);
        if (! $key) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Key not found.'], 404);
        }
        $this->keys->revoke($key, $request->user()?->name);

        return response()->json(['success' => true, 'key' => $this->keys->present($key->fresh())]);
    }

    /**
     * PUT /api/admin/ai-access/keys/{id} {can_create?, can_see_contacts?} —
     * switch on (or off) one of this key's two extra rights: making images /
     * clips and Idea plans in Creatives, and reading the Telegram usernames
     * people gave at sign-up (2026-11-26). Who and when is kept on the key, per
     * switch. A revoked or expired key cannot be switched on.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'can_create' => ['sometimes', 'boolean'],
            'can_see_contacts' => ['sometimes', 'boolean'],
        ]);
        if ($data === []) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOTHING_TO_CHANGE',
                'message' => 'Send can_create or can_see_contacts.',
            ], 422);
        }
        $key = AiAccessKey::find($id);
        if (! $key) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Key not found.'], 404);
        }
        if (in_array(true, array_map('boolval', $data), true) && $key->state() !== 'active') {
            return response()->json([
                'success' => false,
                'error_code' => 'KEY_NOT_ACTIVE',
                'message' => 'Only an active key can be given more access.',
            ], 422);
        }
        $changes = [];
        foreach ($data as $switch => $on) {
            $changes[$switch] = (bool) $on;
            $changes["{$switch}_changed_by_name"] = $request->user()?->name;
            $changes["{$switch}_changed_at"] = now();
        }
        $key->forceFill($changes)->save();

        return response()->json(['success' => true, 'key' => $this->keys->present($key->fresh())]);
    }

    /** GET /api/admin/ai-access/log?key_id=&before_id=&limit= — newest first. */
    public function log(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key_id' => ['nullable', 'integer'],
            'before_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'between:1,200'],
            'tools_only' => ['nullable', 'boolean'],
        ]);
        $limit = (int) ($data['limit'] ?? 100);

        $q = DB::table('ai_access_logs as l')
            ->leftJoin('ai_access_keys as k', 'k.id', '=', 'l.key_id')
            ->orderByDesc('l.id');
        if (! empty($data['key_id'])) {
            $q->where('l.key_id', $data['key_id']);
        }
        if (! empty($data['before_id'])) {
            $q->where('l.id', '<', $data['before_id']);
        }
        if (! empty($data['tools_only'])) {
            $q->where('l.method', 'tools/call');
        }
        $rows = $q->limit($limit + 1)->get([
            'l.id', 'l.key_id', 'k.name as key_name', 'l.method', 'l.tool', 'l.arguments', 'l.status', 'l.error',
            'l.response_bytes', 'l.duration_ms', 'l.ip', 'l.created_at',
        ]);

        $since = now()->subDay();

        return response()->json([
            'success' => true,
            'entries' => $rows->take($limit)->map(fn ($r) => [
                'id' => (int) $r->id,
                'key_id' => $r->key_id !== null ? (int) $r->key_id : null,
                'key_name' => $r->key_name,
                'method' => $r->method,
                'tool' => $r->tool,
                'arguments' => $r->arguments !== null ? json_decode($r->arguments, true) : null,
                'status' => $r->status,
                'error' => $r->error,
                'response_bytes' => $r->response_bytes !== null ? (int) $r->response_bytes : null,
                'duration_ms' => $r->duration_ms !== null ? (int) $r->duration_ms : null,
                'ip' => $r->ip,
                'created_at' => Carbon::parse($r->created_at, 'UTC')->toIso8601String(),
            ])->values(),
            'has_more' => $rows->count() > $limit,
            'last_24h' => [
                'calls' => DB::table('ai_access_logs')->where('created_at', '>=', $since)->count(),
                'questions' => DB::table('ai_access_logs')->where('created_at', '>=', $since)->where('method', 'tools/call')->count(),
                'errors' => DB::table('ai_access_logs')->where('created_at', '>=', $since)->where('status', 'error')->count(),
            ],
        ]);
    }

    /**
     * Where the connector posts. From config on a real box; the request's own
     * origin locally (php artisan serve on :8000 vs WAMP).
     */
    private function serverUrl(Request $request): string
    {
        $base = (string) config('services.ai_access.public_url', '');
        if ($base === '') {
            $base = rtrim($request->getSchemeAndHttpHost(), '/').'/api';
        }

        return rtrim($base, '/').'/mcp';
    }
}
