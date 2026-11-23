<?php

namespace App\Services\Activity;

use App\Models\UserCredential;
use App\Services\Exchanges\ExchangeSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What a person did themselves (Dima, 2026-11-23: "logs in the user so we
 * know all his last actions"). One `user_activity_logs` row per SUCCESSFUL
 * action, written by the `activity:<action>` route middleware after the
 * controller answered 2xx, so a refused password change is never logged as a
 * change.
 *
 * Sign-ins and sign-ups are NOT recorded here: `user_access_events` already
 * holds them (AccessLog) and the readers join it in. Admin actions are the
 * admin audit's (AdminAudit). This is the third leg: the customer's own.
 *
 * Best-effort like AdminAudit: the action is the fact, the row is the telling.
 * Never a credential (key, secret, password) and never a typed contact value
 * in `summary` or `details` — the field NAMES of what changed, not the values.
 */
class UserActivity
{
    /** Stable keys — the Activity page filters on them. Relabel, never rename. */
    public const ACTIONS = [
        'auth.logout',
        'auth.password_reset',
        'email.verified',
        'email.change_requested',
        'email.change_cancelled',
        'profile.updated',
        'profile.image',
        'password.changed',
        'password.set',
        'discord.linked',
        'discord.unlinked',
        'contacts.updated',
        'exchange.connected',
        'exchange.renamed',
        'exchange.rechecked',
        'exchange.disconnected',
        'payment.card_started',
        'payment.crypto_started',
        'payment.txid_sent',
        'payout.wallet_added',
        'payout.wallet_updated',
        'payout.wallet_removed',
        'payout.bank_added',
        'payout.bank_updated',
        'payout.bank_removed',
        'payout.main_set',
        'referral.code_created',
    ];

    /** Plain group per action, for the filters ("Account", "Exchange", "Billing"…). */
    public static function group(string $action): string
    {
        return match (strtok($action, '.')) {
            'exchange' => 'exchange',
            'payment', 'payout', 'referral' => 'billing',
            default => 'account',
        };
    }

    public function record(string $uniId, string $action, string $summary, array $details = [], ?string $ip = null): void
    {
        try {
            DB::table('user_activity_logs')->insert([
                'uni_id' => $uniId,
                'action' => $action,
                'summary' => mb_substr($summary, 0, 255),
                'details' => $details === [] ? null : json_encode($details),
                'ip' => $ip,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('UserActivity: could not record an action.', [
                'action' => $action, 'uni_id' => $uniId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record `$action` for the request that just succeeded. `$data` is the
     * response's JSON (some sentences need what the controller answered).
     */
    public function fromRequest(Request $request, string $action, array $data): void
    {
        $user = $request->user();
        if ($action === 'auth.password_reset') {
            // Signed out by definition: the email that was reset is the subject.
            $user = UserCredential::query()->where('email', trim((string) $request->input('email')))->first();
        }
        if (! $user) {
            return;
        }

        try {
            [$summary, $details] = $this->describe($action, $request, $data);
        } catch (Throwable $e) {
            [$summary, $details] = [self::fallback($action), []];
        }

        $this->record((string) $user->uni_id, $action, $summary, $details, $request->ip());
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function describe(string $action, Request $request, array $data): array
    {
        $exchange = (string) ($request->route('exchange') ?? 'binance');
        $venue = ExchangeSchema::isSupported($exchange) ? ExchangeSchema::for($exchange)->brokerLabel : ucfirst($exchange);
        $id = $request->route('id');
        $invoiceId = $request->route('invoiceId') ?? $request->input('invoice_id');

        return match ($action) {
            'auth.logout' => ['Signed out', []],
            'auth.password_reset' => ['Reset their password with an emailed code', []],
            'email.verified' => ['Confirmed their email address with a code', []],
            'profile.updated' => str_contains((string) ($data['message'] ?? ''), 'sent a code')
                ? ['Asked to change their email (waiting for the code)', []]
                : ['Saved their profile (name: '.mb_substr((string) $request->input('name'), 0, 80).')', []],
            'email.change_cancelled' => ['Cancelled an email change', []],
            'profile.image' => ['Changed their '.($request->input('type') === 'banner' ? 'banner' : 'profile').' picture', []],
            'password.changed' => ['Changed their password', []],
            'password.set' => ['Set a password (the account had Discord sign-in only)', []],
            'discord.linked' => ['Linked their Discord', []],
            'discord.unlinked' => ['Unlinked their Discord', []],
            'contacts.updated' => $this->contacts($request),
            'exchange.connected' => [
                ($data['reconnected'] ?? false ? 'Reconnected' : 'Connected')." a {$venue} account \""
                    .mb_substr((string) $request->input('name'), 0, 60).'"'.($request->boolean('demo') ? ' (demo)' : ' (live)'),
                ['exchange' => $exchange, 'account_id' => $data['account']['id'] ?? null, 'demo' => $request->boolean('demo')],
            ],
            'exchange.renamed' => [
                "Renamed their {$venue} account to \"".mb_substr((string) $request->input('name'), 0, 60).'"',
                ['exchange' => $exchange, 'account_id' => $id === null ? null : (int) $id],
            ],
            'exchange.rechecked' => [
                "Rechecked their {$venue} account (balance and key)",
                ['exchange' => $exchange, 'account_id' => $id === null ? null : (int) $id],
            ],
            'exchange.disconnected' => [
                "Disconnected their {$venue} account".$this->accountName($exchange, $id),
                ['exchange' => $exchange, 'account_id' => $id === null ? null : (int) $id],
            ],
            'payment.card_started' => [
                'Opened card payment (Stripe)'.($invoiceId ? " for invoice #{$invoiceId}" : ''),
                ['invoice_id' => $invoiceId === null ? null : (int) $invoiceId],
            ],
            'payment.crypto_started' => [
                'Opened the USDT payment'.($invoiceId ? " for invoice #{$invoiceId}" : ''),
                ['invoice_id' => $invoiceId === null ? null : (int) $invoiceId],
            ],
            'payment.txid_sent' => [
                'Sent a transaction ID'.($invoiceId ? " for invoice #{$invoiceId}" : ''),
                ['invoice_id' => $invoiceId === null ? null : (int) $invoiceId],
            ],
            'payout.wallet_added' => ['Added a payout wallet', []],
            'payout.wallet_updated' => ['Edited a payout wallet', []],
            'payout.wallet_removed' => ['Removed a payout wallet', []],
            'payout.bank_added' => ['Added a bank account for payouts', []],
            'payout.bank_updated' => ['Edited a bank account for payouts', []],
            'payout.bank_removed' => ['Removed a bank account for payouts', []],
            'payout.main_set' => ['Changed their main payout method', []],
            'referral.code_created' => ['Created a referral code', []],
            default => [self::fallback($action), []],
        };
    }

    /** Which handles changed, never the handles themselves. */
    private function contacts(Request $request): array
    {
        $fields = array_values(array_filter(
            ['telegram' => 'Telegram', 'whatsapp' => 'WhatsApp', 'discord' => 'Discord'],
            fn (string $key) => $request->has($key),
            ARRAY_FILTER_USE_KEY,
        ));

        return [
            'Updated their contact details'.($fields ? ' ('.implode(', ', $fields).')' : ''),
            [],
        ];
    }

    private function accountName(string $exchange, mixed $id): string
    {
        if ($id === null || ! ExchangeSchema::isSupported($exchange)) {
            return '';
        }
        $name = DB::table(ExchangeSchema::for($exchange)->accountsTable)->where('id', (int) $id)->value('name');

        return $name ? ' "'.mb_substr((string) $name, 0, 60).'"' : '';
    }

    private static function fallback(string $action): string
    {
        return ucfirst(str_replace(['.', '_'], ' ', $action));
    }
}
