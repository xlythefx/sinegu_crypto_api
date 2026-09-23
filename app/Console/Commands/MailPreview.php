<?php

namespace App\Console\Commands;

use App\Mail\AccountApproved;
use App\Mail\NewRegistrationNotice;
use App\Mail\PasswordResetCode;
use Illuminate\Console\Command;
use Illuminate\Mail\Mailable;

/**
 * Render every Pixel Alpha email to a static HTML file you can open in a
 * browser. NOTHING IS SENT — no transport is touched, no address is read.
 *
 * It renders the real Mailables, not copies of their markup: a preview built
 * from a duplicate template drifts from what customers actually receive, and
 * then it is worse than no preview at all.
 */
class MailPreview extends Command
{
    protected $signature = 'mail:preview {--dir= : Output directory (default: storage/app/mail-previews)}';

    protected $description = 'Render the account emails to HTML files for design review — sends nothing';

    public function handle(): int
    {
        $dir = rtrim((string) ($this->option('dir') ?: storage_path('app/mail-previews')), '\\/');
        // storage_path() answers with forward slashes on Windows too; one
        // separator per path keeps the printed line copy-pasteable.
        $dir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Could not create {$dir}");

            return self::FAILURE;
        }

        $samples = $this->samples();

        foreach ($samples as $sample) {
            file_put_contents($dir.DIRECTORY_SEPARATOR.$sample['slug'].'.html', $sample['html']);
            $this->line("  <info>✓</info> {$sample['slug']}.html  — {$sample['subject']}");
        }

        file_put_contents($dir.DIRECTORY_SEPARATOR.'index.html', $this->index($samples));

        $this->newLine();
        $this->info('Open this file in a browser:');
        $this->line('  '.$dir.DIRECTORY_SEPARATOR.'index.html');

        return self::SUCCESS;
    }

    /**
     * One entry per email, with the sample data a reviewer needs to judge the
     * layout: a long-ish name, a real-shaped uni_id, a six-digit code.
     *
     * @return list<array{slug:string,title:string,to:string,subject:string,html:string,note:string}>
     */
    private function samples(): array
    {
        return [
            $this->render(
                'new-registration',
                'New registration → admin',
                (string) (config('mail.admin_address') ?: 'the admin address (MAIL_ADMIN_ADDRESS — not set)'),
                'Sent the moment someone registers, by password or by Discord.',
                new NewRegistrationNotice(
                    name: 'Jonathan Meyer',
                    email: 'jonathan.meyer@example.com',
                    uniId: '9f3c1a7e-4b28-4d16-9f5a-2c8e0d71b3aa',
                    via: 'Email + password',
                    registeredAt: now()->format('d M Y, H:i').' UTC',
                ),
            ),
            $this->render(
                'account-approved',
                'Account approved → user',
                'the trader who was approved',
                'Sent when an admin accepts a pending registration. A rejection sends nothing.',
                new AccountApproved(name: 'Jonathan'),
            ),
            $this->render(
                'password-reset-code',
                'Password reset → user',
                'whoever asked to reset their password',
                'Already live. Shown here because it shares the same frame.',
                new PasswordResetCode('Jonathan', '408217', 15),
            ),
        ];
    }

    /** @return array{slug:string,title:string,to:string,subject:string,html:string,note:string} */
    private function render(string $slug, string $title, string $to, string $note, Mailable $mailable): array
    {
        return [
            'slug' => $slug,
            'title' => $title,
            'to' => $to,
            'note' => $note,
            'subject' => (string) $mailable->envelope()->subject,
            'html' => $mailable->render(),
        ];
    }

    /**
     * The contact sheet: every email in one page, each in an iframe so the
     * template's own styles cannot leak into the chrome around it. The width
     * buttons are there because most of these are read on a phone.
     *
     * @param  list<array{slug:string,title:string,to:string,subject:string,html:string,note:string}>  $samples
     */
    private function index(array $samples): string
    {
        $cards = '';

        foreach ($samples as $s) {
            $title = e($s['title']);
            $subject = e($s['subject']);
            $to = e($s['to']);
            $note = e($s['note']);
            $slug = e($s['slug']);

            $cards .= <<<HTML
            <section class="card">
              <header>
                <h2>{$title}</h2>
                <p class="note">{$note}</p>
                <dl>
                  <div><dt>Subject</dt><dd>{$subject}</dd></div>
                  <div><dt>To</dt><dd>{$to}</dd></div>
                </dl>
                <a class="open" href="./{$slug}.html" target="_blank" rel="noreferrer">Open on its own ↗</a>
              </header>
              <div class="frame"><iframe src="./{$slug}.html" title="{$title}"></iframe></div>
            </section>
            HTML;
        }

        $generated = e(now()->format('d M Y, H:i'));

        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title>Pixel Alpha — email previews</title>
          <style>
            :root { --bg:#0a0c11; --surface:#12151d; --border:#232836; --text:#e7ecf3; --muted:#98a2b3; --accent:#d9ad55; }
            * { box-sizing: border-box; }
            body { margin:0; padding:32px 20px 64px; background:var(--bg); color:var(--text);
                   font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; }
            .wrap { max-width: 1040px; margin: 0 auto; }
            h1 { font-size: 26px; letter-spacing:-0.02em; margin:0 0 6px; }
            .sub { color: var(--muted); font-size: 14px; margin: 0 0 6px; line-height:1.6; }
            .stamp { color:#5b6475; font-size:12px; margin:0 0 26px; }
            .widths { display:flex; gap:8px; margin: 0 0 26px; flex-wrap:wrap; }
            .widths button { background:var(--surface); color:var(--text); border:1px solid var(--border);
                             padding:8px 16px; border-radius:999px; font-size:13px; cursor:pointer; }
            .widths button[aria-pressed="true"] { border-color:var(--accent); color:var(--accent); }
            .card { background:var(--surface); border:1px solid var(--border); border-radius:16px;
                    padding:22px; margin:0 0 22px; }
            .card h2 { font-size:17px; margin:0 0 4px; }
            .note { color:var(--muted); font-size:13px; margin:0 0 14px; line-height:1.6; }
            dl { margin:0 0 14px; font-size:13px; }
            dl > div { display:flex; gap:10px; padding:3px 0; }
            dt { color:#5b6475; width:64px; flex:none; }
            dd { margin:0; color:var(--text); word-break:break-word; }
            .open { font-size:13px; color:var(--accent); text-decoration:none; }
            .frame { margin-top:16px; display:flex; justify-content:center; background:#f4f5f7;
                     border-radius:12px; overflow:hidden; }
            iframe { width:100%; max-width:var(--w, 680px); height:720px; border:0; background:#f4f5f7;
                     transition:max-width .2s ease; }
          </style>
        </head>
        <body>
          <div class="wrap">
            <h1>Pixel Alpha — email previews</h1>
            <p class="sub">Rendered from the real Mailables. Nothing here was sent, and no addresses were used.</p>
            <p class="stamp">Generated {$generated} · php artisan mail:preview</p>

            <div class="widths">
              <button data-w="680" aria-pressed="true">Desktop</button>
              <button data-w="480" aria-pressed="false">Tablet</button>
              <button data-w="360" aria-pressed="false">Phone</button>
            </div>

            {$cards}
          </div>
          <script>
            const buttons = document.querySelectorAll('.widths button')
            buttons.forEach((b) => b.addEventListener('click', () => {
              buttons.forEach((o) => o.setAttribute('aria-pressed', String(o === b)))
              document.documentElement.style.setProperty('--w', b.dataset.w + 'px')
            }))
          </script>
        </body>
        </html>
        HTML;
    }
}
