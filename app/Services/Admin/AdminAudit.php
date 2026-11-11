<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Who did what to which customer (2026-11-11, the admin audit). One row per
 * admin action: approved, rejected, suspended / reactivated, role or fee
 * change, deleted, an invoice marked paid (and how), edited or deleted, a
 * key disconnected. The customer's Timeline reads it.
 *
 * Best-effort, like every other record of an action: the action is the fact,
 * the row is the telling — a failed insert is logged and never undoes or
 * fails what the admin did. Never put a credential in `details`.
 */
class AdminAudit
{
    public const ACTIONS = [
        'user.created', 'user.approved', 'user.rejected', 'user.status', 'user.role', 'user.fees',
        'user.deleted', 'invoice.marked_paid', 'invoice.fee_changed', 'invoice.deleted',
        'invoice.attributed', 'account.disconnected', 'account.purged', 'account.updated', 'note.deleted',
    ];

    /**
     * @param  string|null  $actor  the admin's uni_id; null = the system
     * @param  string|null  $subject  the customer the action was about
     * @param  string  $summary  one plain sentence ("Marked invoice #15 paid: bank transfer")
     * @param  array<string, mixed>  $details  the before → after values
     */
    public function record(?string $actor, ?string $subject, string $action, string $summary, array $details = []): void
    {
        try {
            DB::table('admin_audit_logs')->insert([
                'actor_uni_id' => $actor,
                'subject_uni_id' => $subject,
                'action' => $action,
                'summary' => mb_substr($summary, 0, 255),
                'details' => $details === [] ? null : json_encode($details),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('AdminAudit: could not record an admin action.', [
                'action' => $action, 'subject' => $subject, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** "20% → 15%" style: only the keys that actually changed. */
    public static function changes(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $key => $value) {
            $old = $before[$key] ?? null;
            if ((string) $old !== (string) $value) {
                $out[$key] = ['from' => $old, 'to' => $value];
            }
        }

        return $out;
    }
}
