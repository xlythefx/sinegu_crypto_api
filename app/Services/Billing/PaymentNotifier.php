<?php

namespace App\Services\Billing;

use App\Mail\InvoicePaidNotice;
use App\Mail\PaymentReceived;
use App\Models\Invoice;
use App\Services\EngineCache;
use App\Services\Notifications\AccountMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * "An invoice was paid" — told three ways, once, the moment it settles
 * (2026-11-11, after a customer paid by card and nobody heard about it):
 *
 *   - the customer:  PaymentReceived ("thank you", trading back on if paused)
 *   - the team:      InvoicePaidNotice to AccountMail::teamRecipients()
 *   - the admin Telegram group, through the engine (it owns the bot)
 *
 * Called ONLY by InvoiceService::settle(), after the commit, and only when that
 * call did the settling, so every rail (card, USDT, Coinsbuy, manual
 * attribution, "Mark paid") tells it exactly once, and a replayed webhook
 * never does.
 *
 * Best-effort like every other notice: the paid row is the fact, the message
 * is the telling. Nothing here can throw back into a settlement.
 */
class PaymentNotifier
{
    private const SANDBOX_KEY_PREFIX = 'SBXINV-';

    public function __construct(
        private EngineCache $engine,
        // How it was paid — the same words the admin invoice page shows.
        private PaymentMethod $methods,
    ) {}

    /**
     * @param  bool  $wasPaused  trading had been switched off for this invoice and
     *                           settling it switched it back on
     * @return array{customer: bool, team: bool, telegram: bool}|null  null = deliberately silent
     */
    public function paid(Invoice $invoice, bool $wasPaused = false): ?array
    {
        try {
            return $this->tell($invoice, $wasPaused);
        } catch (Throwable $e) {
            Log::warning('PaymentNotifier: could not announce a payment.', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Whether a settled invoice is something to announce at all. */
    public static function announces(Invoice $invoice): bool
    {
        // A $0 month collected nothing; the scenario runner's scratch rows
        // belong to a real user's uni_id and must never thank them for a
        // payment that never happened.
        return $invoice->feeCents() > 0
            && ! str_starts_with((string) $invoice->api_key, self::SANDBOX_KEY_PREFIX);
    }

    /** @return array{customer: bool, team: bool, telegram: bool}|null */
    private function tell(Invoice $invoice, bool $wasPaused): ?array
    {
        if (! self::announces($invoice)) {
            return null;
        }

        $owner = DB::table('user_credentials')->where('uni_id', $invoice->user_id)
            ->first(['uni_id', 'name', 'email', 'type']);
        $ownName = trim((string) ($owner->name ?? ''));
        $name = $ownName !== '' ? $ownName : 'A customer';
        $email = trim((string) ($owner->email ?? ''));

        $method = $this->methods->describe($invoice);
        $test = $method['test'] || ($owner->type ?? null) === 'developer';
        $amount = round($invoice->paid_amount !== null ? (float) $invoice->paid_amount : $invoice->feeCents() / 100, 2);
        $period = Carbon::createFromFormat('!Y-m', (string) $invoice->month_year)->format('F Y');
        $paidAt = Carbon::parse($invoice->paid_at ?? now(), 'UTC')->format('j M Y, H:i').' UTC';
        $reference = trim((string) $invoice->payment_reference) ?: '—';

        $sent = ['customer' => false, 'team' => false, 'telegram' => false];

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $sent['customer'] = $this->send('payment-received', $invoice, [$email], new PaymentReceived(
                name: $this->firstName($ownName),
                invoiceId: (int) $invoice->id,
                period: $period,
                amount: $amount,
                paidAt: $paidAt,
                method: $method['label'],
                reference: $reference,
                wasPaused: $wasPaused,
            ));
        }

        $team = AccountMail::teamRecipients();
        if ($team === []) {
            Log::warning('PaymentNotifier: no mail.admin_address configured — team notice skipped.', ['invoice_id' => $invoice->id]);
        } else {
            $sent['team'] = $this->send('team-invoice-paid', $invoice, $team, new InvoicePaidNotice(
                name: $name,
                email: $email,
                uniId: (string) $invoice->user_id,
                invoiceId: (int) $invoice->id,
                period: $period,
                amount: $amount,
                method: $method['label'],
                reference: $reference,
                paidAt: $paidAt,
            ));
        }

        $sent['telegram'] = $this->engine->paymentReceived([
            'invoice_id' => (int) $invoice->id,
            'name' => $name,
            'amount' => $amount,
            'period' => $period,
            'method' => $method['label'],
            'exchange' => (string) ($invoice->exchange ?: 'binance'),
            'was_paused' => $wasPaused,
            'test' => $test,
        ]);

        return $sent;
    }

    /** @param  list<string>  $to */
    private function send(string $what, Invoice $invoice, array $to, $mail): bool
    {
        try {
            Mail::to($to)->send($mail);

            return true;
        } catch (Throwable $e) {
            Log::warning("PaymentNotifier: {$what} failed to send.", ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function firstName(string $name): string
    {
        $first = trim(explode(' ', $name)[0] ?? '');

        return $first !== '' ? $first : 'there';
    }
}
