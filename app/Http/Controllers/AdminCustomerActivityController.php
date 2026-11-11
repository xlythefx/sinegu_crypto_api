<?php

namespace App\Http\Controllers;

use App\Models\TradeLog;
use App\Models\UserCredential;
use App\Services\Admin\AdminAudit;
use App\Services\Admin\CustomerTimeline;
use App\Services\Admin\SignalHealth;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A customer's profile, the parts the admin audit asked for (2026-11-11):
 *
 *   GET    /admin/users/{uniId}/timeline     everything that happened, newest first
 *   POST   /admin/users/{uniId}/notes        { body } — a note on the customer
 *   DELETE /admin/users/{uniId}/notes/{id}
 *   GET    /admin/users/{uniId}/signals      the bot's last signals for them, and why it skipped
 *
 * Full admins only (the `admin` route group): notes carry billing talk, and
 * the Signal Log is not a collaborator page either.
 */
class AdminCustomerActivityController extends Controller
{
    /** How far back the signal card looks (the log is a few signals a day). */
    private const SIGNAL_SCAN = 600;

    public function __construct(private CustomerTimeline $timeline, private AdminAudit $audit) {}

    public function timeline(string $uniId): JsonResponse
    {
        $user = UserCredential::find($uniId);
        if (! $user) {
            return $this->notFound();
        }

        return response()->json(['success' => true, 'events' => $this->timeline->for($user)]);
    }

    public function addNote(Request $request, string $uniId): JsonResponse
    {
        if (! UserCredential::whereKey($uniId)->exists()) {
            return $this->notFound();
        }
        $body = trim((string) $request->validate(['body' => ['required', 'string', 'max:2000']])['body']);
        if ($body === '') {
            return response()->json(['success' => false, 'message' => 'Write something first.'], 422);
        }

        $id = DB::table('user_notes')->insertGetId([
            'uni_id' => $uniId,
            'author_uni_id' => $request->user()->uni_id,
            'body' => $body,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'note_id' => $id], 201);
    }

    public function deleteNote(Request $request, string $uniId, int $id): JsonResponse
    {
        $note = DB::table('user_notes')->where('id', $id)->where('uni_id', $uniId)->first();
        if (! $note) {
            return response()->json(['success' => false, 'message' => 'Note not found.'], 404);
        }
        DB::table('user_notes')->where('id', $id)->delete();
        $this->audit->record($request->user()->uni_id, $uniId, 'note.deleted', 'Deleted a note',
            ['body' => mb_substr((string) $note->body, 0, 200)]);

        return response()->json(['success' => true]);
    }

    /**
     * The last signals that reached this customer's accounts — filled, or
     * skipped / failed and WHY (SignalHealth's plain-English reasons, the
     * Signal Log's own), plus a 7-day tally. Reads `trade_logs` only.
     */
    public function signals(Request $request, string $uniId): JsonResponse
    {
        if (! UserCredential::whereKey($uniId)->exists()) {
            return $this->notFound();
        }
        $limit = max(1, min(30, (int) $request->query('limit', 10)));

        $venues = [];
        foreach (ExchangeSchema::supported() as $exchange) {
            if (DB::table(ExchangeSchema::for($exchange)->accountsTable)->where('uni_id', $uniId)->exists()) {
                $venues[] = $exchange;
            }
        }

        $since = Carbon::now()->subDays(7);
        $tally = ['filled' => 0, 'skipped' => 0, 'failed' => 0];
        $rows = [];
        if ($venues) {
            $logs = TradeLog::query()->whereIn('exchange', $venues)
                ->orderByDesc('id')->limit(self::SIGNAL_SCAN)->get();
            foreach ($logs as $log) {
                $details = is_array($log->details) ? $log->details : [];
                foreach ($details as $d) {
                    if (! is_array($d) || ($d['uni_id'] ?? null) !== $uniId) {
                        continue;
                    }
                    $status = (string) ($d['status'] ?? 'unknown');
                    // received_at is cast; created_at comes back as a plain string.
                    $raw = $log->received_at ?? $log->created_at;
                    $at = $raw ? Carbon::parse($raw, 'UTC') : null;
                    if ($at && $at->gte($since) && isset($tally[$status])) {
                        $tally[$status]++;
                    }
                    if (count($rows) < $limit) {
                        $reason = SignalHealth::classify($d, $log->category);
                        $rows[] = [
                            'log_id' => $log->id,
                            'at' => $at?->toIso8601String(),
                            'exchange' => $log->exchange,
                            'feed' => $log->feed,
                            'action' => $log->action,
                            'ticker' => $log->ticker,
                            'account' => $d['account'] ?? null,
                            'status' => $status,
                            'reason' => $reason,
                        ];
                    }
                }
            }
        }

        return response()->json([
            'success' => true,
            'signals' => $rows,
            'last_7_days' => $tally,
            'scanned' => self::SIGNAL_SCAN,
        ]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'User not found.'], 404);
    }
}
