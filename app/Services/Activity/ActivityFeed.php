<?php

namespace App\Services\Activity;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin → Activity (Dima, 2026-11-23: "see last activity across all users"):
 * one newest-first list built from the three records that already exist —
 *
 *   customer  user_activity_logs   what a person did themselves (UserActivity)
 *   signin    user_access_events   sign-ups and sign-ins (AccessLog)
 *   admin     admin_audit_logs     what an admin did, and to whom (AdminAudit)
 *
 * Nothing is copied: the list is a UNION ALL read, so the customer's Timeline
 * and this page can never disagree about what happened. Times are UTC in the
 * DB; the page shows them in the reader's zone.
 */
class ActivityFeed
{
    public const SOURCES = ['customer', 'signin', 'admin'];

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{source?: ?string, uni_id?: ?string, q?: ?string, from?: ?string, to?: ?string, page?: int, per_page?: int}  $f
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $f): array
    {
        $source = in_array($f['source'] ?? null, self::SOURCES, true) ? $f['source'] : null;
        $perPage = max(10, min(self::MAX_PER_PAGE, (int) ($f['per_page'] ?? 50)));
        $page = max(1, (int) ($f['page'] ?? 1));

        $people = null;
        if (! empty($f['uni_id'])) {
            $people = [(string) $f['uni_id']];
        }
        $q = trim((string) ($f['q'] ?? ''));
        $nameHits = [];
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $nameHits = DB::table('user_credentials')
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('uni_id', $q))
                ->limit(200)->pluck('uni_id')->all();
        }
        $from = ! empty($f['from']) ? Carbon::parse($f['from'], 'UTC')->startOfDay() : null;
        $to = ! empty($f['to']) ? Carbon::parse($f['to'], 'UTC')->endOfDay() : null;

        $parts = [];
        if ($source === null || $source === 'customer') {
            $parts[] = $this->narrow(DB::table('user_activity_logs')->selectRaw(
                "'customer' as source, id, uni_id as subject_uni_id, uni_id as actor_uni_id, action, summary, details, ip, "
                .'null as country, null as device, null as browser, null as os, created_at'
            ), 'uni_id', 'uni_id', 'summary', $people, $q, $nameHits, $from, $to);
        }
        if ($source === null || $source === 'signin') {
            $parts[] = $this->narrow(DB::table('user_access_events')->selectRaw(
                "'signin' as source, id, uni_id as subject_uni_id, uni_id as actor_uni_id, event as action, method as summary, null as details, ip, "
                .'country, device_type as device, browser, os, created_at'
            ), 'uni_id', 'uni_id', null, $people, $q, $nameHits, $from, $to);
        }
        if ($source === null || $source === 'admin') {
            $parts[] = $this->narrow(DB::table('admin_audit_logs')->selectRaw(
                "'admin' as source, id, subject_uni_id, actor_uni_id, action, summary, details, null as ip, "
                .'null as country, null as device, null as browser, null as os, created_at'
            ), 'subject_uni_id', 'actor_uni_id', 'summary', $people, $q, $nameHits, $from, $to);
        }

        $union = array_shift($parts);
        foreach ($parts as $p) {
            $union->unionAll($p);
        }
        $base = DB::query()->fromSub($union, 'feed');
        $total = (clone $base)->count();
        $rows = $base->orderByDesc('created_at')->orderByDesc('id')
            ->offset(($page - 1) * $perPage)->limit($perPage)->get();

        $ids = $rows->pluck('subject_uni_id')->merge($rows->pluck('actor_uni_id'))->filter()->unique()->all();
        $users = $ids === [] ? collect() : DB::table('user_credentials')->whereIn('uni_id', $ids)
            ->get(['uni_id', 'name', 'type'])->keyBy('uni_id');

        $items = $rows->map(function ($r) use ($users) {
            $subject = $r->subject_uni_id ? $users->get($r->subject_uni_id) : null;
            $actor = $r->actor_uni_id ? $users->get($r->actor_uni_id) : null;

            return [
                'key' => $r->source.':'.$r->id,
                'source' => $r->source,
                'at' => Carbon::parse($r->created_at, 'UTC')->toIso8601String(),
                'action' => $r->action,
                'area' => $this->area($r->source, (string) $r->action),
                'title' => $r->source === 'signin' ? self::signinTitle((string) $r->action, (string) $r->summary) : (string) $r->summary,
                'detail' => $r->source === 'signin' ? self::device($r) : null,
                'ip' => $r->ip,
                'country' => $r->country,
                'subject' => $r->subject_uni_id ? [
                    'uni_id' => $r->subject_uni_id,
                    'name' => $subject->name ?? null,
                    'type' => $subject->type ?? null,
                ] : null,
                'actor' => $r->source === 'admin' ? [
                    'uni_id' => $r->actor_uni_id,
                    'name' => $r->actor_uni_id ? ($actor->name ?? $r->actor_uni_id) : 'System',
                ] : null,
            ];
        })->values()->all();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    private function narrow(Builder $q, string $subjectCol, string $actorCol, ?string $textCol, ?array $people, string $text, array $nameHits, ?Carbon $from, ?Carbon $to): Builder
    {
        if ($people !== null) {
            $q->where(fn ($w) => $w->whereIn($subjectCol, $people)->orWhereIn($actorCol, $people));
        }
        if ($text !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $text).'%';
            $q->where(function ($w) use ($subjectCol, $actorCol, $textCol, $nameHits, $like) {
                $w->whereIn($subjectCol, $nameHits ?: ['-'])->orWhereIn($actorCol, $nameHits ?: ['-']);
                if ($textCol !== null) {
                    $w->orWhere($textCol, 'like', $like);
                }
            });
        }
        if ($from) {
            $q->where('created_at', '>=', $from);
        }
        if ($to) {
            $q->where('created_at', '<=', $to);
        }

        return $q;
    }

    /** account | exchange | billing | signin — the page's colour and filter. */
    private function area(string $source, string $action): string
    {
        if ($source === 'signin') {
            return 'signin';
        }
        $head = strtok($action, '.');

        return match ($head) {
            'exchange', 'account' => 'exchange',
            'payment', 'payout', 'referral', 'invoice' => 'billing',
            default => 'account',
        };
    }

    public static function signinTitle(string $event, string $method): string
    {
        $via = $method === 'discord' ? 'Discord' : 'email + password';

        return $event === 'register' ? "Signed up ({$via})" : "Signed in ({$via})";
    }

    /** "Chrome 141 · Windows · desktop" */
    public static function device(object $r): ?string
    {
        $device = $r->device ?? $r->device_type ?? null;
        $parts = array_filter([$r->browser ?? null, $r->os ?? null, $device && $device !== 'unknown' ? $device : null]);

        return $parts ? implode(' · ', $parts) : null;
    }
}
