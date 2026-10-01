<?php

namespace App\Services\Billing;

use App\Mail\InvoiceIssued;
use App\Mail\PaymentReminder;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The customer's half of the monthly billing schedule (owner, 2026-10-01):
 *
 *   1st  invoice issued   (InvoiceIssued)
 *   2nd  gentle reminder  (PaymentReminder::STAGE_GENTLE)
 *   3rd  firm reminder    (PaymentReminder::STAGE_FIRM — names the pause day)
 *   4th  trading paused   (PaymentReminder::STAGE_PAUSED) — due date, account disabled
 *
 * Every email RESTATES the invoice row, never recomputes it. Best-effort like
 * AccountMail: the row is the fact, the email is the telling, so a failed send
 * is logged and reported as not sent — it never fails the run that billed or
 * paused the account.
 */
class InvoiceNotifier
{
    public function issued(Invoice $invoice): bool
    {
        $c = $this->customer($invoice);
        if ($c === null) {
            return false;
        }

        return $this->send($invoice, 'invoice-issued', $c->email, new InvoiceIssued(
            name: $this->firstName($c->name),
            invoiceId: (int) $invoice->id,
            period: $this->period($invoice),
            exchange: 'Binance',
            profit: round((float) $invoice->new_realized_profit, 2),
            feeRate: (float) ($c->realized_percentage ?? 20),
            amount: round((float) $invoice->total_fee, 2),
            dueDate: $this->day($invoice->due_date),
            unrealizedFee: round((float) $invoice->fee_unrealized, 2),
            unrealizedRate: (float) ($c->unrealized_percentage ?? 6),
            unrealizedProfit: round((float) $invoice->new_unrealized_profit, 2),
        ));
    }

    /** @param  PaymentReminder::STAGE_*  $stage */
    public function reminder(Invoice $invoice, string $stage): bool
    {
        $c = $this->customer($invoice);
        if ($c === null) {
            return false;
        }

        return $this->send($invoice, "reminder-{$stage}", $c->email, new PaymentReminder(
            stage: $stage,
            name: $this->firstName($c->name),
            invoiceId: (int) $invoice->id,
            period: $this->period($invoice),
            amount: round((float) $invoice->total_fee, 2),
            issuedOn: $this->day($invoice->created_at),
            pauseDate: $this->day($invoice->due_date),
        ));
    }

    private function customer(Invoice $invoice): ?object
    {
        $c = DB::table('user_credentials')->where('uni_id', $invoice->user_id)
            ->first(['name', 'email', 'realized_percentage', 'unrealized_percentage']);

        return $c && filter_var(trim((string) $c->email), FILTER_VALIDATE_EMAIL) ? $c : null;
    }

    private function send(Invoice $invoice, string $what, string $to, $mail): bool
    {
        try {
            Mail::to(trim($to))->send($mail);

            return true;
        } catch (Throwable $e) {
            Log::warning("InvoiceNotifier: {$what} failed to send.", ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function firstName(?string $name): string
    {
        $first = trim(explode(' ', trim((string) $name))[0] ?? '');

        return $first !== '' ? $first : 'there';
    }

    private function period(Invoice $invoice): string
    {
        return Carbon::createFromFormat('!Y-m', (string) $invoice->month_year)->format('F Y');
    }

    private function day(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('j M Y') : '—';
    }
}
