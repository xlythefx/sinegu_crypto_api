<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\TronTransfer;
use App\Services\Payments\PaymentEnvironment;
use App\Services\Payments\TronUnits;
use Illuminate\Support\Facades\DB;

/**
 * HOW an invoice was paid, in the words a person uses — the ONE answer, read
 * by the payment notices (email + Telegram, PaymentNotifier) and by the admin
 * invoice page (GET /admin/invoices/{id}), so the two can never name the same
 * payment differently.
 *
 * Read off what InvoiceService::settle() stored: `payment_provider` (the rail)
 * and `payment_reference` (Stripe's checkout session id, or the TRON tx hash).
 * A test payment — a Stripe test session, or USDT on the Nile testnet — is
 * flagged, so a developer's rehearsal never reads as revenue.
 */
class PaymentMethod
{
    /** How the money came, for a payment an admin marks by hand (2026-11-11). */
    public const MANUAL_METHODS = ['bank_transfer', 'crypto_wallet', 'cash', 'other'];

    public function __construct(private PaymentEnvironment $env) {}

    /** "Bank transfer" … — null (a payment marked before 2026-11-11) reads as before. */
    public static function manualLabel(?string $method): string
    {
        return match ($method) {
            'bank_transfer' => 'Bank transfer',
            'crypto_wallet' => 'Crypto to another wallet',
            'cash' => 'Cash',
            'other' => 'Other',
            default => 'Marked paid by an admin',
        };
    }

    /**
     * @return array{provider: ?string, kind: string, label: string, test: bool}
     *   kind: card | crypto | manual | unknown (paid before the rail was recorded)
     */
    public function describe(Invoice $invoice): array
    {
        $provider = $invoice->payment_provider === null ? null : (string) $invoice->payment_provider;
        $reference = (string) $invoice->payment_reference;

        [$kind, $label, $test] = match ($provider) {
            'stripe' => str_starts_with($reference, 'cs_test_')
                ? ['card', 'Card (Stripe test mode)', true]
                : ['card', 'Card (Stripe)', false],
            'tron' => $this->transfer($invoice)?->network === 'nile'
                ? ['crypto', 'USDT (TRC-20, Nile testnet)', true]
                : ['crypto', 'USDT (TRC-20)', false],
            'coinsbuy' => ['crypto', 'Crypto (Coinsbuy)', false],
            // The customer's email reads this too, so it names the way the
            // money came and never the admin (that is `marked_by`, admin-only).
            'manual' => ['manual', $invoice->manual_method
                ? self::manualLabel($invoice->manual_method).' (marked paid by an admin)'
                : 'Marked paid by an admin', false],
            null, '' => ['unknown', 'Not recorded', false],
            default => ['unknown', ucfirst($provider), false],
        };

        return ['provider' => $provider ?: null, 'kind' => $kind, 'label' => $label, 'test' => $test];
    }

    /**
     * Everything the admin invoice page shows about the payment; null while
     * the invoice is unpaid. `received` is what actually arrived on chain
     * (a USDT payment can land short by an exchange's withdrawal fee), and
     * `explorer_url` opens that transaction on TronScan.
     *
     * @return array<string, mixed>|null
     */
    public function details(Invoice $invoice): ?array
    {
        if ($invoice->status !== 'paid') {
            return null;
        }

        $out = $this->describe($invoice) + [
            'reference' => trim((string) $invoice->payment_reference) ?: null,
            'paid_amount' => $invoice->paid_amount === null ? null : round((float) $invoice->paid_amount, 2),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'network' => null,
            'explorer_url' => null,
            'received' => null,
            // A payment an ADMIN recorded: by hand ("Mark paid"), or a USDT
            // transfer matched to this invoice by hand on Crypto Transfers.
            'manual_method' => $invoice->manual_method,
            'note' => $invoice->payment_note,
            'marked_by' => $this->person($invoice->paid_by),
        ];

        $transfer = $out['provider'] === 'tron' ? $this->transfer($invoice) : null;
        if ($transfer) {
            $tron = $this->env->tron((string) $transfer->network);
            $out['network'] = $transfer->network;
            // 'watcher' = matched automatically; 'admin:{uni_id}' = by hand.
            $by = (string) $transfer->settled_by;
            if ($by !== '' && $by !== 'watcher') {
                $out['marked_by'] ??= $this->person(str_starts_with($by, 'admin:') ? substr($by, 6) : $by);
                $out['note'] ??= $transfer->note ?: null;
            }
            $out['explorer_url'] = $tron['explorer_tx'] !== '' ? $tron['explorer_tx'].$transfer->tx_hash : null;
            if ($transfer->value_units !== null) {
                $out['received'] = [
                    'amount' => TronUnits::format((string) $transfer->value_units, (int) $tron['decimals']),
                    'asset' => $tron['asset'],
                ];
            }
        }

        return $out;
    }

    /** @return array{uni_id: string, name: string}|null */
    private function person(?string $uniId): ?array
    {
        if (! $uniId) {
            return null;
        }
        $name = DB::table('user_credentials')->where('uni_id', $uniId)->value('name');

        return ['uni_id' => $uniId, 'name' => $name ?: $uniId];
    }

    /** The on-chain transfer that paid this invoice, when it was paid on TRON. */
    private function transfer(Invoice $invoice): ?TronTransfer
    {
        $hash = trim((string) $invoice->payment_reference);
        if ($hash === '') {
            return null;
        }

        return TronTransfer::where('tx_hash', $hash)->where('invoice_id', $invoice->id)->first();
    }
}
