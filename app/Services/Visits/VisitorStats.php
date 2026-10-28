<?php

namespace App\Services\Visits;

use App\Models\SiteVisit;
use App\Models\UserCredential;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Admin → Visitors: everything the page shows, read from site_visits (and,
 * for the sign-up half, user_credentials / user_signup_sources).
 *
 * Rules every figure follows:
 *  - Suspicious rows never count. They exist for the log's "Suspicious" view.
 *  - "Visitors" are DAILY unique (VisitRecorder's daily hash): a person who
 *    comes back on three days is three visitors in a 30-day window. A range
 *    figure is therefore the sum of its days, which is what makes it survive
 *    the IP purge.
 *  - Days are UTC, like every other admin page; a range is whole days, both
 *    ends inclusive.
 *  - A sign-up is a customer account (`type = 'user'`) created in the range.
 */
final class VisitorStats
{
    /** "On the site now" = a counted view in the last this many minutes. */
    public const LIVE_MINUTES = 5;

    /** A longer window is charted per week, not per day. */
    private const WEEKLY_AFTER_DAYS = 92;

    /** Rows per card for the long lists (sources, countries, …). */
    private const TOP = 12;

    public const LOG_PER_PAGE_MAX = 100;

    /**
     * @return array{from: string, to: string, start: Carbon, end: Carbon}
     *   `end` is exclusive (the day after `to`).
     */
    public function range(?string $from, ?string $to): array
    {
        $today = Carbon::now('UTC')->startOfDay();
        $first = SiteVisit::min('created_at');

        $start = self::day($from) ?? ($first ? Carbon::parse($first, 'UTC')->startOfDay() : $today->copy());
        $endDay = self::day($to) ?? $today->copy();
        if ($start->gt($endDay)) {
            [$start, $endDay] = [$endDay, $start];
        }

        return [
            'from' => $start->toDateString(),
            'to' => $endDay->toDateString(),
            'start' => $start,
            'end' => $endDay->copy()->addDay(),
        ];
    }

    public function summary(?string $from, ?string $to): array
    {
        $r = $this->range($from, $to);
        $clean = fn () => $this->counted($r['start'], $r['end']);

        $visitors = $clean()->distinct()->count('visitor_hash');
        $signupIds = UserCredential::where('type', 'user')
            ->where('created_at', '>=', $r['start'])
            ->where('created_at', '<', $r['end'])
            ->pluck('uni_id');
        $signups = $signupIds->count();

        $first = SiteVisit::min('created_at');

        return [
            'range' => ['from' => $r['from'], 'to' => $r['to']],
            'since' => $first ? Carbon::parse($first, 'UTC')->toDateString() : null,
            'ip_days' => VisitRecorder::IP_DAYS,
            'totals' => [
                'visitors' => $visitors,
                'views' => $clean()->count(),
                'signups' => $signups,
                'signup_rate' => $visitors > 0 ? round($signups / $visitors * 100, 1) : null,
                'live_now' => SiteVisit::where('suspicious', false)
                    ->where('created_at', '>=', Carbon::now('UTC')->subMinutes(self::LIVE_MINUTES))
                    ->distinct()
                    ->count('visitor_hash'),
            ],
            ...$this->series($r),
            'funnel' => $this->funnel($r, $visitors, $signupIds),
            'pages' => $clean()
                ->select('path')
                ->selectRaw('COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors')
                ->groupBy('path')
                ->orderByDesc('visitors')
                ->get()
                ->map(fn ($row) => ['path' => $row->path, 'views' => (int) $row->views, 'visitors' => (int) $row->visitors])
                ->values(),
            'sources' => $this->sources($clean),
            'referral_codes' => $this->referralCodes($clean),
            'campaigns' => $clean()
                ->where(fn (Builder $q) => $q->whereNotNull('utm_source')->orWhereNotNull('utm_campaign'))
                ->select('utm_source', 'utm_campaign')
                ->selectRaw('COUNT(DISTINCT visitor_hash) as visitors')
                ->groupBy('utm_source', 'utm_campaign')
                ->orderByDesc('visitors')
                ->limit(self::TOP)
                ->get()
                ->map(fn ($row) => [
                    'source' => $row->utm_source,
                    'campaign' => $row->utm_campaign,
                    'visitors' => (int) $row->visitors,
                ])
                ->values(),
            'heard_about' => DB::table('user_signup_sources as s')
                ->join('user_credentials as u', 'u.uni_id', '=', 's.uni_id')
                ->where('u.type', 'user')
                ->where('u.created_at', '>=', $r['start'])
                ->where('u.created_at', '<', $r['end'])
                ->select('s.source')
                ->selectRaw('COUNT(*) as answers')
                ->groupBy('s.source')
                ->orderByDesc('answers')
                ->get()
                ->map(fn ($row) => ['slug' => $row->source, 'count' => (int) $row->answers])
                ->values(),
            'countries' => $this->grouped($clean, 'country', fn ($v) => $v, self::TOP),
            'devices' => $this->grouped($clean, 'device_type', fn ($v) => $v),
            'browsers' => $this->grouped($clean, 'browser', fn ($v) => self::family($v), 8),
            'systems' => $this->grouped($clean, 'os', fn ($v) => self::family($v), 8),
            'filtered' => $this->filtered($r),
        ];
    }

    /**
     * The visitor log, newest first. Filters: view (clean | suspicious | all),
     * page, country, device, ip (exact — set by clicking an address) and q
     * (an IP prefix typed in the search box).
     */
    public function log(array $f, int $limit, int $offset): array
    {
        $r = $this->range($f['from'] ?? null, $f['to'] ?? null);
        $q = SiteVisit::query()
            ->where('created_at', '>=', $r['start'])
            ->where('created_at', '<', $r['end']);

        $view = in_array($f['view'] ?? null, ['clean', 'suspicious', 'all'], true) ? $f['view'] : 'clean';
        if ($view !== 'all') {
            $q->where('suspicious', $view === 'suspicious');
        }

        $options = [
            'pages' => (clone $q)->distinct()->orderBy('path')->pluck('path')->values(),
            'countries' => (clone $q)->whereNotNull('country')->distinct()->orderBy('country')->pluck('country')->values(),
        ];

        if ($path = VisitPages::normalise($f['page'] ?? null)) {
            $q->where('path', $path);
        }
        $country = strtoupper((string) ($f['country'] ?? ''));
        if (preg_match('/^[A-Z0-9]{2}$/', $country)) {
            $q->where('country', $country);
        }
        if (in_array($f['device'] ?? null, ['desktop', 'mobile', 'tablet'], true)) {
            $q->where('device_type', $f['device']);
        }
        $ip = self::ipText($f['ip'] ?? null);
        if ($ip !== null) {
            $q->where('ip', $ip);
        }
        $search = self::ipText($f['q'] ?? null);
        if ($search !== null) {
            $q->where('ip', 'like', addcslashes($search, '%_\\').'%');
        }

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->offset($offset)->get();
        $accounts = $this->accountsByIp($rows->pluck('ip')->filter()->unique()->values());

        return [
            'success' => true,
            'range' => ['from' => $r['from'], 'to' => $r['to']],
            'rows' => $rows->map(fn (SiteVisit $v) => [
                'id' => $v->id,
                'at' => $v->created_at?->toISOString(),
                'path' => $v->path,
                'ip' => $v->ip,
                'country' => $v->country,
                'device_type' => $v->device_type,
                'browser' => $v->browser,
                'os' => $v->os,
                'source' => $v->is_entry ? VisitSources::label($v->referrer_host) : null,
                'referrer_host' => $v->referrer_host,
                'ref_code' => $v->ref_code,
                'utm_source' => $v->utm_source,
                'utm_campaign' => $v->utm_campaign,
                'entry' => $v->is_entry,
                'signed_in' => $v->signed_in,
                'suspicious' => $v->suspicious,
                'suspicious_reason' => $v->suspicious_reason,
                'accounts' => $v->ip ? ($accounts[$v->ip] ?? []) : [],
            ])->values(),
            'pagination' => ['total' => $total, 'limit' => $limit, 'offset' => $offset],
            'options' => $options,
            'ip_detail' => $ip !== null ? $this->ipDetail($ip, $accounts[$ip] ?? null) : null,
        ];
    }

    /** Counted (never suspicious) views in [start, end). */
    private function counted(Carbon $start, Carbon $end): Builder
    {
        return SiteVisit::query()
            ->where('suspicious', false)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end);
    }

    /**
     * Visitors, views and sign-ups per day — or per week (Monday) once the
     * window is longer than WEEKLY_AFTER_DAYS. Every bucket is present,
     * empty ones as zeros, so the chart's x axis is the calendar.
     */
    private function series(array $r): array
    {
        $views = $this->counted($r['start'], $r['end'])
            ->selectRaw('DATE(created_at) as d, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors')
            ->groupByRaw('DATE(created_at)')
            ->get()
            ->keyBy('d');
        $signups = UserCredential::where('type', 'user')
            ->where('created_at', '>=', $r['start'])
            ->where('created_at', '<', $r['end'])
            ->selectRaw('DATE(created_at) as d, COUNT(*) as n')
            ->groupByRaw('DATE(created_at)')
            ->pluck('n', 'd');

        $weekly = $r['start']->diffInDays($r['end']) > self::WEEKLY_AFTER_DAYS;
        $buckets = [];
        for ($day = $r['start']->copy(); $day->lt($r['end']); $day->addDay()) {
            $d = $day->toDateString();
            $key = $weekly ? $day->copy()->startOfWeek(Carbon::MONDAY)->toDateString() : $d;
            $buckets[$key] ??= ['start' => $key, 'visitors' => 0, 'views' => 0, 'signups' => 0];
            $buckets[$key]['visitors'] += (int) ($views[$d]->visitors ?? 0);
            $buckets[$key]['views'] += (int) ($views[$d]->views ?? 0);
            $buckets[$key]['signups'] += (int) ($signups[$d] ?? 0);
        }

        return ['bucket' => $weekly ? 'week' : 'day', 'series' => array_values($buckets)];
    }

    /**
     * From a visit to a connected exchange, for the period: visitors, those
     * who opened the sign-in / register page, then the period's sign-ups and
     * how far each of them has got since.
     *
     * @param  Collection<int, string>  $signupIds
     */
    private function funnel(array $r, int $visitors, Collection $signupIds): array
    {
        $users = UserCredential::whereIn('uni_id', $signupIds)->get(['uni_id', 'status', 'email_verified']);

        $connected = collect();
        foreach (ExchangeSchema::supported() as $exchange) {
            $connected = $connected->merge(
                ExchangeSchema::for($exchange)->accountQuery()
                    ->whereIn('uni_id', $signupIds)
                    ->where('demo', 0)
                    ->pluck('uni_id')
            );
        }

        return [
            ['key' => 'visited', 'count' => $visitors],
            ['key' => 'opened_signup', 'count' => $this->counted($r['start'], $r['end'])
                ->where('path', '/auth')->distinct()->count('visitor_hash')],
            ['key' => 'signed_up', 'count' => $signupIds->count()],
            ['key' => 'verified', 'count' => $users->filter(fn ($u) => (bool) $u->email_verified)->count()],
            ['key' => 'approved', 'count' => $users->where('status', 'active')->count()],
            ['key' => 'connected', 'count' => $connected->unique()->count()],
        ];
    }

    /** Referring sites by visitors, merged by label (google.com + google.co.th = Google). */
    private function sources(callable $clean): Collection
    {
        return $clean()
            ->where('is_entry', true)
            ->select('referrer_host')
            ->selectRaw('COUNT(DISTINCT visitor_hash) as visitors')
            ->groupBy('referrer_host')
            ->get()
            ->groupBy(fn ($row) => VisitSources::label($row->referrer_host))
            ->map(fn (Collection $rows, string $label) => [
                'label' => $label,
                'direct' => $label === VisitSources::DIRECT,
                'hosts' => $rows->pluck('referrer_host')->filter()->values(),
                'visitors' => (int) $rows->sum('visitors'),
            ])
            ->sortByDesc('visitors')
            ->take(self::TOP)
            ->values();
    }

    /** Invite links (`?ref=`) by visitors, with whose code it is. */
    private function referralCodes(callable $clean): Collection
    {
        $rows = $clean()
            ->whereNotNull('ref_code')
            ->select('ref_code')
            ->selectRaw('COUNT(DISTINCT visitor_hash) as visitors')
            ->groupBy('ref_code')
            ->orderByDesc('visitors')
            ->limit(self::TOP)
            ->get();

        $owners = DB::table('referral_codes as c')
            ->join('user_credentials as u', 'u.uni_id', '=', 'c.user_uni_id')
            ->whereIn('c.code', $rows->pluck('ref_code'))
            ->get(['c.code', 'u.uni_id', 'u.name'])
            ->keyBy('code');

        return $rows->map(fn ($row) => [
            'code' => $row->ref_code,
            'owner' => isset($owners[$row->ref_code])
                ? ['uni_id' => $owners[$row->ref_code]->uni_id, 'name' => $owners[$row->ref_code]->name]
                : null,
            'visitors' => (int) $row->visitors,
        ])->values();
    }

    /**
     * Visitors per value of one column, folded through $key (browser
     * "Chrome 141" → "Chrome"), biggest first. A null value is `null`.
     */
    private function grouped(callable $clean, string $column, callable $key, ?int $top = null): Collection
    {
        $rows = $clean()
            ->select($column)
            ->selectRaw('COUNT(DISTINCT visitor_hash) as visitors')
            ->groupBy($column)
            ->get()
            ->groupBy(fn ($row) => $row->{$column} === null ? '' : $key($row->{$column}))
            ->map(fn (Collection $rows, string $value) => [
                'value' => $value === '' ? null : $value,
                'visitors' => (int) $rows->sum('visitors'),
            ])
            ->sortByDesc('visitors')
            ->values();

        return $top ? $rows->take($top)->values() : $rows;
    }

    /** What the spam rules threw away in the window, per reason, plus the suspicious rows kept. */
    private function filtered(array $r): array
    {
        $hits = DB::table('site_visit_filtered')
            ->where('day', '>=', $r['from'])
            ->where('day', '<=', $r['to'])
            ->select('reason')
            ->selectRaw('SUM(hits) as total')
            ->groupBy('reason')
            ->pluck('total', 'reason');

        $out = [];
        foreach (VisitRecorder::FILTERS as $reason) {
            $out[$reason] = (int) ($hits[$reason] ?? 0);
        }
        $out['suspicious'] = SiteVisit::where('suspicious', true)
            ->where('created_at', '>=', $r['start'])
            ->where('created_at', '<', $r['end'])
            ->count();

        return $out;
    }

    /**
     * Accounts that signed up or signed in from each IP (user_access_events),
     * so the log can say "this visitor became Juan".
     *
     * @param  Collection<int, string>  $ips
     * @return array<string, list<array{uni_id: string, name: ?string}>>
     */
    private function accountsByIp(Collection $ips): array
    {
        if ($ips->isEmpty()) {
            return [];
        }

        $out = [];
        DB::table('user_access_events as e')
            ->join('user_credentials as u', 'u.uni_id', '=', 'e.uni_id')
            ->whereIn('e.ip', $ips)
            ->select('e.ip', 'u.uni_id', 'u.name')
            ->selectRaw('MIN(e.created_at) as first_at')
            ->groupBy('e.ip', 'u.uni_id', 'u.name')
            ->orderBy('first_at')
            ->get()
            ->each(function ($row) use (&$out) {
                $out[$row->ip][] = ['uni_id' => $row->uni_id, 'name' => $row->name];
            });

        return $out;
    }

    /** The strip above the log when it is narrowed to one address. */
    private function ipDetail(string $ip, ?array $accounts): array
    {
        $rows = SiteVisit::where('ip', $ip);

        return [
            'ip' => $ip,
            'views' => (clone $rows)->count(),
            'suspicious_views' => (clone $rows)->where('suspicious', true)->count(),
            'first_seen' => ($first = (clone $rows)->min('created_at')) ? Carbon::parse($first, 'UTC')->toISOString() : null,
            'last_seen' => ($last = (clone $rows)->max('created_at')) ? Carbon::parse($last, 'UTC')->toISOString() : null,
            'countries' => (clone $rows)->whereNotNull('country')->distinct()->pluck('country')->values(),
            'accounts' => $accounts ?? [],
        ];
    }

    /** "Chrome 141" → "Chrome", "iOS 18" → "iOS". */
    private static function family(string $value): string
    {
        return preg_replace('/\s+\d+$/', '', $value) ?? $value;
    }

    private static function day(?string $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        // '!' so nothing is filled in from the current time.
        $day = Carbon::createFromFormat('!Y-m-d', $value, 'UTC');

        return $day && $day->toDateString() === $value ? $day : null;
    }

    /** An IP (or the start of one) as typed: digits, hex, dots and colons only. */
    private static function ipText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return $value !== '' && strlen($value) <= 45 && preg_match('/^[0-9a-f.:]+$/', $value) ? $value : null;
    }
}
