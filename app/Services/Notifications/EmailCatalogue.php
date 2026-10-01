<?php

namespace App\Services\Notifications;

use App\Mail\AccountApproved;
use App\Mail\EmailVerificationCode;
use App\Mail\ExchangeConnectedNotice;
use App\Mail\InvoiceIssued;
use App\Mail\InvoiceIssuedNotice;
use App\Mail\InvoicePaidNotice;
use App\Mail\InvoiceUnpaidNotice;
use App\Mail\NewRegistrationNotice;
use App\Mail\PasswordResetCode;
use App\Mail\PaymentReceived;
use App\Mail\PaymentReminder;
use App\Mail\PixelMail;
use App\Mail\WeeklySummary;
use App\Mail\WelcomePending;
use App\Services\Auth\EmailVerification;

/**
 * Every email the product sends, with realistic sample data — the ONE list
 * behind Admin → Sandbox → Email Templates and `php artisan mail:preview`.
 *
 * `live` says whether production actually sends it today. A draft is designed
 * and reviewable but has no sender wired yet: its wording is waiting for the
 * owner's approval (to-do `email-templates-approval`). Flip it to live in the
 * same change that wires its send.
 *
 * Samples are rendered from the real Mailables, so what a reviewer approves is
 * byte-for-byte what a customer would get — only the figures are invented.
 */
class EmailCatalogue
{
    public const AUDIENCE_CUSTOMER = 'customer';

    public const AUDIENCE_TEAM = 'team';

    /**
     * @return list<array{slug:string,audience:string,title:string,trigger:string,to:string,live:bool,note:string,mail:PixelMail}>
     */
    public static function all(): array
    {
        $team = implode(', ', AccountMail::teamRecipients()) ?: 'the team (MAIL_ADMIN_ADDRESS — not set)';

        // One invoice threads through every billing sample, so the reminders,
        // the receipt and the team notices all describe the same bill.
        $inv = [
            'id' => 1042,
            'period' => 'September 2026',
            'amount' => 496.10,
            'profit' => 2480.50,
            'issued' => '1 Oct 2026',
            'due' => '4 Oct 2026',
            'pause' => '4 Oct 2026',
        ];
        $customer = ['name' => 'Jonathan Meyer', 'first' => 'Jonathan', 'email' => 'jonathan.meyer@example.com', 'uni' => '9f3c1a7e-4b28-4d16-9f5a-2c8e0d71b3aa'];
        $tx = '7c1e9a42f0b3d5e8a1c6f47b29d0e3a58b6c41f7e2d9a0b35c8e1f64a7d2b90c';

        return [
            // ── To the customer ────────────────────────────────────────────
            [
                'slug' => 'welcome',
                'audience' => self::AUDIENCE_CUSTOMER,
                'title' => 'Welcome — waiting for approval',
                'trigger' => 'The moment someone registers (password or Discord).',
                'to' => 'The new customer',
                'live' => false,
                'note' => 'Links to the Binance connection guide and the FAQ so the wait is spent getting ready.',
                'mail' => new WelcomePending($customer['first']),
            ],
            [
                'slug' => 'account-approved',
                'audience' => self::AUDIENCE_CUSTOMER,
                'title' => 'Account approved',
                'trigger' => 'When an admin approves a pending registration. A rejection sends nothing.',
                'to' => 'The approved customer',
                'live' => true,
                'note' => 'Already sent today.',
                'mail' => new AccountApproved(name: $customer['first']),
            ],
            [
                'slug' => 'weekly-summary',
                'audience' => self::AUDIENCE_CUSTOMER,
                'title' => 'Weekly summary',
                'trigger' => 'Every week, to customers with a connected live account.',
                'to' => 'Each customer',
                'live' => false,
                'note' => 'P&L in dollars after exchange fees, with the before-fees figure and fees beside it.',
                'mail' => new WeeklySummary(
                    name: $customer['first'],
                    weekLabel: '22 – 28 Sep 2026',
                    pnl: 312.48,
                    fees: 18.62,
                    returnPct: 2.41,
                    trades: 14,
                    wins: 10,
                    balance: 13268.90,
                    bestDayLabel: 'Wed 24 Sep',
                    bestDayPnl: 146.20,
                    assets: [
                        ['symbol' => 'LTCUSDT', 'trades' => 8, 'pnl' => 228.10],
                        ['symbol' => 'BTCUSDT', 'trades' => 4, 'pnl' => 121.75],
                        ['symbol' => 'ETHUSDT', 'trades' => 2, 'pnl' => -37.37],
                    ],
                ),
            ],
            [
                'slug' => 'invoice-issued',
                'audience' => self::AUDIENCE_CUSTOMER,
                'title' => 'New invoice (day 0)',
                'trigger' => 'The 1st of the month, when the engine invoices last month (16:00 Thailand time).',
                'to' => 'The invoiced customer',
                'live' => true,
                'note' => 'Payment schedule: invoice on the 1st → reminder the 2nd → reminder the 3rd → trading paused the 4th (the due date), all at the billing hour.',
                'mail' => new InvoiceIssued(
                    name: $customer['first'],
                    invoiceId: $inv['id'],
                    period: $inv['period'],
                    exchange: 'Binance',
                    profit: $inv['profit'],
                    feeRate: 20,
                    amount: $inv['amount'],
                    dueDate: $inv['due'],
                ),
            ],
            self::reminder('reminder-day-2', 'Payment reminder (the 2nd)', 'The 2nd of the month at the billing hour, if still unpaid.', PaymentReminder::STAGE_GENTLE, $customer, $inv),
            self::reminder('reminder-day-3', 'Payment reminder (the 3rd)', 'The 3rd of the month at the billing hour, if still unpaid.', PaymentReminder::STAGE_FIRM, $customer, $inv),
            self::reminder('reminder-day-5', 'Trading paused (the 4th)', 'The 4th — the due date — at the billing hour, if still unpaid: the account stops trading.', PaymentReminder::STAGE_PAUSED, $customer, $inv),
            [
                'slug' => 'payment-received',
                'audience' => self::AUDIENCE_CUSTOMER,
                'title' => 'Thank you for paying',
                'trigger' => 'When an invoice is paid.',
                'to' => 'The customer who paid',
                'live' => false,
                'note' => 'If trading had been paused for the invoice, it says trading is back on.',
                'mail' => new PaymentReceived(
                    name: $customer['first'],
                    invoiceId: $inv['id'],
                    period: $inv['period'],
                    amount: $inv['amount'],
                    paidAt: '3 Oct 2026, 14:22 UTC',
                    method: 'USDT (TRC-20)',
                    reference: $tx,
                ),
            ],
            [
                'slug' => 'password-reset-code',
                'audience' => self::AUDIENCE_CUSTOMER,
                'title' => 'Password reset code',
                'trigger' => 'When someone asks to reset their password.',
                'to' => 'Whoever asked',
                'live' => true,
                'note' => 'Already sent today.',
                'mail' => new PasswordResetCode($customer['first'], '408217', 15),
            ],
            [
                'slug' => 'email-verification-code',
                'audience' => self::AUDIENCE_CUSTOMER,
                'title' => 'Email verification code',
                'trigger' => 'Right after someone registers (and on "Resend code").',
                'to' => 'The new sign-up',
                'live' => true,
                'note' => 'Sent today. The team\'s sign-up notice now waits until this code is entered.',
                'mail' => new EmailVerificationCode($customer['first'], '408217', EmailVerification::CODE_TTL_MINUTES),
            ],

            // ── To the team ────────────────────────────────────────────────
            [
                'slug' => 'team-new-registration',
                'audience' => self::AUDIENCE_TEAM,
                'title' => 'New sign-up',
                'trigger' => 'The moment a new sign-up confirms their email (or signs up through Discord with a verified email).',
                'to' => $team,
                'live' => true,
                'note' => 'Already sent today.',
                'mail' => new NewRegistrationNotice(
                    name: $customer['name'],
                    email: $customer['email'],
                    uniId: $customer['uni'],
                    via: AccountMail::VIA_PASSWORD,
                    registeredAt: '28 Sep 2026, 10:53 UTC',
                ),
            ],
            [
                'slug' => 'team-exchange-connected',
                'audience' => self::AUDIENCE_TEAM,
                'title' => 'Exchange connected',
                'trigger' => 'When a customer connects an exchange account.',
                'to' => $team,
                'live' => false,
                'note' => '',
                'mail' => new ExchangeConnectedNotice(
                    name: $customer['name'],
                    email: $customer['email'],
                    uniId: $customer['uni'],
                    exchange: 'Binance',
                    mode: 'Live',
                    balance: 5250.00,
                    connectedAt: '29 Sep 2026, 09:12 UTC',
                ),
            ],
            [
                'slug' => 'team-invoice-issued',
                'audience' => self::AUDIENCE_TEAM,
                'title' => 'Invoice issued',
                'trigger' => 'When a customer is invoiced.',
                'to' => $team,
                'live' => false,
                'note' => '',
                'mail' => new InvoiceIssuedNotice(
                    name: $customer['name'],
                    email: $customer['email'],
                    uniId: $customer['uni'],
                    invoiceId: $inv['id'],
                    period: $inv['period'],
                    exchange: 'Binance',
                    amount: $inv['amount'],
                    dueDate: $inv['due'],
                ),
            ],
            [
                'slug' => 'team-invoice-unpaid',
                'audience' => self::AUDIENCE_TEAM,
                'title' => 'Invoice still unpaid',
                'trigger' => 'With each customer reminder (day 2, day 3) and when trading pauses (day 5).',
                'to' => $team,
                'live' => false,
                'note' => 'Shown at day 3. So the team knows who to chase before trading pauses.',
                'mail' => new InvoiceUnpaidNotice(
                    name: $customer['name'],
                    email: $customer['email'],
                    uniId: $customer['uni'],
                    invoiceId: $inv['id'],
                    period: $inv['period'],
                    amount: $inv['amount'],
                    daysOpen: 3,
                    issuedOn: $inv['issued'],
                    pauseDate: $inv['pause'],
                ),
            ],
            [
                'slug' => 'team-invoice-paid',
                'audience' => self::AUDIENCE_TEAM,
                'title' => 'Invoice paid',
                'trigger' => 'When a customer pays an invoice.',
                'to' => $team,
                'live' => false,
                'note' => '',
                'mail' => new InvoicePaidNotice(
                    name: $customer['name'],
                    email: $customer['email'],
                    uniId: $customer['uni'],
                    invoiceId: $inv['id'],
                    period: $inv['period'],
                    amount: $inv['amount'],
                    method: 'USDT (TRC-20)',
                    reference: $tx,
                    paidAt: '3 Oct 2026, 14:22 UTC',
                ),
            ],
        ];
    }

    /** @return array{slug:string,audience:string,title:string,trigger:string,to:string,live:bool,note:string,mail:PixelMail}|null */
    public static function find(string $slug): ?array
    {
        foreach (self::all() as $entry) {
            if ($entry['slug'] === $slug) {
                return $entry;
            }
        }

        return null;
    }

    private static function reminder(string $slug, string $title, string $trigger, string $stage, array $c, array $inv): array
    {
        return [
            'slug' => $slug,
            'audience' => self::AUDIENCE_CUSTOMER,
            'title' => $title,
            'trigger' => $trigger,
            'to' => 'The customer with the unpaid invoice',
            'live' => true,
            'note' => 'The team sees who is still unpaid in the admin Telegram chat at the same moment.',
            'mail' => new PaymentReminder(
                stage: $stage,
                name: $c['first'],
                invoiceId: $inv['id'],
                period: $inv['period'],
                amount: $inv['amount'],
                issuedOn: $inv['issued'],
                pauseDate: $inv['pause'],
            ),
        ];
    }
}
