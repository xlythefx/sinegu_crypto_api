<?php

namespace App\Services\Admin;

use App\Models\Invoice;
use App\Models\UserCredential;
use App\Services\Activity\ActivityFeed;
use App\Services\Activity\UserActivity;
use App\Services\Billing\PaymentMethod;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A customer's story on one screen, newest first (2026-11-11, the admin
 * audit): signed up → approved → connected a key → first trade → each
 * invoice issued, gone overdue, paid (and HOW, and by whom) → key refused →
 * disconnected, with every admin action and every note in between.
 *
 * Nothing new is recorded for it except the admin audit and the notes; the
 * rest is read from the rows that already exist (accounts, invoices, closed
 * trades). So admin actions start on 2026-11-11, while invoices, keys and
 * trades go back to the beginning.
 */
class CustomerTimeline
{
    public function __construct(private PaymentMethod $methods) {}

    /** @return list<array{at: string, kind: string, tone: string, title: string, detail: ?string, by: ?string, invoice_id: ?int, note_id: ?int}> */
    public function for(UserCredential $user): array
    {
        $events = [];
        $add = function (?Carbon $at, string $kind, string $tone, string $title, ?string $detail = null, array $extra = []) use (&$events) {
            if ($at === null) {
                return;
            }
            $events[] = [
                'at' => $at->toIso8601String(),
                'kind' => $kind,
                'tone' => $tone,
                'title' => $title,
                'detail' => $detail,
                'by' => $extra['by'] ?? null,
                'invoice_id' => $extra['invoice_id'] ?? null,
                'note_id' => $extra['note_id'] ?? null,
            ];
        };

        $add($user->created_at, 'signup', 'info', 'Signed up', $user->email);

        // ---- admin actions -------------------------------------------------
        $audit = DB::table('admin_audit_logs')->where('subject_uni_id', $user->uni_id)->orderBy('id')->get();
        $names = DB::table('user_credentials')
            ->whereIn('uni_id', $audit->pluck('actor_uni_id')->filter()->unique()->all())
            ->pluck('name', 'uni_id');
        $handledAccounts = [];
        foreach ($audit as $row) {
            $details = json_decode((string) $row->details, true) ?: [];
            // The invoice's own "Paid" line already says how and by whom.
            if (in_array($row->action, ['invoice.marked_paid', 'invoice.attributed'], true)) {
                continue;
            }
            if (in_array($row->action, ['account.disconnected', 'account.purged'], true)) {
                $handledAccounts[($details['exchange'] ?? '').':'.($details['account_id'] ?? '')] = true;
            }
            $add(Carbon::parse($row->created_at, 'UTC'), 'admin', self::auditTone($row->action), $row->summary, null, [
                'by' => $row->actor_uni_id ? ($names[$row->actor_uni_id] ?? $row->actor_uni_id) : 'System',
                'invoice_id' => isset($details['invoice_id']) ? (int) $details['invoice_id'] : null,
            ]);
        }

        // ---- exchange accounts and trades ------------------------------------
        $firstTrade = null;
        foreach (ExchangeSchema::supported() as $exchange) {
            $schema = ExchangeSchema::for($exchange);
            $venue = $exchange === 'mexc' ? 'MEXC' : ucfirst($exchange);
            $accounts = DB::table($schema->accountsTable)->where('uni_id', $user->uni_id)
                ->get(['id', 'api_key', 'name', 'demo', 'is_sandbox', 'created_at', 'deleted_at', 'key_status', 'key_error_reason', 'key_blocked_at']);
            foreach ($accounts as $a) {
                if ($a->is_sandbox) {
                    continue;
                }
                $label = "{$venue} account \"{$a->name}\"".($a->demo ? ' (demo)' : '');
                $add($a->created_at ? Carbon::parse($a->created_at, 'UTC') : null, 'account', 'good', "Connected the {$label}");
                if ($a->deleted_at && ! isset($handledAccounts["{$exchange}:{$a->id}"])) {
                    $add(Carbon::parse($a->deleted_at, 'UTC'), 'account', 'bad', "Disconnected the {$label}");
                }
                if ($a->key_status === 'blocked' && $a->key_blocked_at && ! $a->deleted_at) {
                    $add(Carbon::parse($a->key_blocked_at, 'UTC'), 'key', 'bad', "{$venue} refused the API key",
                        $a->key_error_reason ? 'Reason: '.str_replace('_', ' ', strtolower($a->key_error_reason)) : null);
                }
            }

            $real = $accounts->filter(fn ($a) => ! $a->demo && ! $a->is_sandbox)->pluck('api_key')->all();
            if ($real) {
                $first = DB::table($schema->pastPositions)->whereIn('api_key', $real)->where('is_sandbox', 0)->min('closed_at');
                if ($first && ($firstTrade === null || $first < $firstTrade[0])) {
                    $firstTrade = [$first, $venue];
                }
            }
        }
        if ($firstTrade) {
            $add(Carbon::parse($firstTrade[0], 'UTC'), 'trade', 'good', 'First closed trade', "On {$firstTrade[1]}");
        }

        // ---- invoices --------------------------------------------------------
        $invoices = Invoice::query()->forUser($user->uni_id)
            ->where(fn ($q) => $q->whereNull('api_key')->orWhere('api_key', 'not like', 'SBXINV-%'))
            ->orderBy('month_year')->get();
        foreach ($invoices as $inv) {
            $month = Carbon::createFromFormat('!Y-m', (string) $inv->month_year)->format('F Y');
            $fee = $inv->feeCents() / 100;
            $ref = ['invoice_id' => (int) $inv->id];
            if ($fee <= 0) {
                $add($inv->created_at, 'invoice', 'neutral', "No fee for {$month}", 'The account made no profit above its high-water mark.', $ref);

                continue;
            }
            $add($inv->created_at, 'invoice', 'info', "{$month} invoice issued: $".number_format($fee, 2), "Due {$inv->due_date?->format('M j, Y')}", $ref);

            $due = $inv->due_date?->copy()->endOfDay();
            $paid = $inv->status === 'paid' ? $inv->paid_at : null;
            if ($due && $due->isPast() && ($paid === null || $paid->gt($due))) {
                $add($due->copy()->startOfDay()->addDay(), 'invoice', 'bad', "{$month} invoice overdue",
                    'Unpaid after the due date: trading pauses until it is paid.', $ref);
            }
            if ($paid) {
                $p = $this->methods->details($inv);
                $add($paid, 'payment', 'good', "Paid the {$month} invoice: $".number_format($fee, 2),
                    trim(($p['label'] ?? '').($p['note'] ? ' · "'.$p['note'].'"' : '')),
                    $ref + ['by' => $p['marked_by']['name'] ?? null]);
            }
        }

        // ---- notes -----------------------------------------------------------
        $notes = DB::table('user_notes')->where('uni_id', $user->uni_id)->get();
        $authors = DB::table('user_credentials')
            ->whereIn('uni_id', $notes->pluck('author_uni_id')->filter()->unique()->all())
            ->pluck('name', 'uni_id');
        foreach ($notes as $n) {
            $add(Carbon::parse($n->created_at, 'UTC'), 'note', 'note', 'Note', $n->body, [
                'by' => $n->author_uni_id ? ($authors[$n->author_uni_id] ?? $n->author_uni_id) : null,
                'note_id' => (int) $n->id,
            ]);
        }

        // ---- their own actions (2026-11-23) ----------------------------------
        // Connect / disconnect are already told above from the account rows
        // (back to the beginning); the log only adds what nothing else holds.
        $own = DB::table('user_activity_logs')->where('uni_id', $user->uni_id)
            ->whereNotIn('action', ['exchange.connected', 'exchange.disconnected'])
            ->orderByDesc('id')->limit(500)->get();
        foreach ($own as $row) {
            $add(Carbon::parse($row->created_at, 'UTC'), 'customer', self::customerTone($row->action), $row->summary,
                $row->ip ? 'IP '.$row->ip : null);
        }

        // ---- sign-ins (recorded since 2026-10-14) ----------------------------
        $signins = DB::table('user_access_events')->where('uni_id', $user->uni_id)->where('event', 'login')
            ->orderByDesc('id')->limit(200)->get();
        foreach ($signins as $s) {
            $where = implode(' · ', array_filter([ActivityFeed::device($s), $s->country, $s->ip]));
            $add(Carbon::parse($s->created_at, 'UTC'), 'signin', 'neutral',
                ActivityFeed::signinTitle('login', (string) $s->method), $where !== '' ? $where : null);
        }

        usort($events, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return $events;
    }

    private static function customerTone(string $action): string
    {
        return match (UserActivity::group($action)) {
            'billing' => 'info',
            default => 'neutral',
        };
    }

    private static function auditTone(string $action): string
    {
        return match ($action) {
            'user.approved', 'user.created' => 'good',
            'user.rejected', 'user.deleted', 'invoice.deleted', 'account.disconnected', 'account.purged' => 'bad',
            default => 'neutral',
        };
    }
}
