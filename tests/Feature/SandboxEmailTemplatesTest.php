<?php

namespace Tests\Feature;

use App\Mail\PixelMail;
use App\Models\UserCredential;
use App\Services\Notifications\EmailCatalogue;
use Illuminate\Support\Facades\Mail;

/**
 * Admin → Sandbox → Email Templates: every email renders from sample data,
 * and a preview copy is unmistakably a preview.
 */
class SandboxEmailTemplatesTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // The shipped default, pinned so a local MAIL_PREVIEW_DOMAINS cannot
        // change what these tests measure.
        config(['mail.preview_domains' => ['pixel-alpha.com', 'feature-digital.com']]);
    }

    private function headers(string $uniId): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.UserCredential::find($uniId)->createToken('spa')->plainTextToken];
    }

    public function test_every_catalogued_email_renders_with_a_subject(): void
    {
        $slugs = [];
        foreach (EmailCatalogue::all() as $e) {
            $this->assertInstanceOf(PixelMail::class, $e['mail']);
            $this->assertNotSame('', (string) $e['mail']->envelope()->subject, $e['slug']);
            $this->assertStringContainsString('Pixel Alpha', $e['mail']->render(), $e['slug']);
            $slugs[] = $e['slug'];
        }

        $this->assertSame($slugs, array_unique($slugs), 'slugs must be unique — the page routes on them');
    }

    public function test_the_listing_is_admin_only(): void
    {
        $user = $this->makeUser(['type' => 'user']);
        $this->getJson('/api/admin/sandbox/emails', $this->headers($user))->assertForbidden();

        $admin = $this->makeUser(['type' => 'admin']);
        $this->getJson('/api/admin/sandbox/emails', $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('emails.0.slug', 'welcome')
            ->assertJsonStructure(['emails' => [['slug', 'audience', 'title', 'subject', 'html', 'live']], 'team_recipients']);
    }

    public function test_a_test_copy_is_marked_as_a_preview(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);

        $this->postJson('/api/admin/sandbox/emails/send', ['to' => 'reviewer@pixel-alpha.com', 'slug' => 'reminder-day-5'], $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('sent', 1);

        Mail::assertSent(PixelMail::class, fn (PixelMail $mail) => $mail->hasTo('reviewer@pixel-alpha.com')
            && str_starts_with((string) $mail->envelope()->subject, '[Preview] '));
    }

    public function test_send_all_mails_every_template_to_the_reviewer_only(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);

        $this->postJson('/api/admin/sandbox/emails/send', ['to' => 'reviewer@pixel-alpha.com'], $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('sent', count(EmailCatalogue::all()));

        Mail::assertSentCount(count(EmailCatalogue::all()));
        // Sample data names a customer; nothing may go to that address.
        Mail::assertNotSent(PixelMail::class, fn (PixelMail $mail) => $mail->hasTo('jonathan.meyer@example.com'));
    }

    public function test_an_unknown_template_is_a_404(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);

        $this->postJson('/api/admin/sandbox/emails/send', ['to' => 'reviewer@pixel-alpha.com', 'slug' => 'nope'], $this->headers($admin))
            ->assertNotFound();
        Mail::assertNothingSent();
    }

    /* ---- who a preview may go to ---- */

    public function test_a_preview_may_go_to_the_admins_own_address(): void
    {
        $admin = $this->makeUser(['type' => 'admin', 'email' => 'Me@Elsewhere.test']);

        // Case does not matter: the address on file is what it is compared to.
        $this->postJson('/api/admin/sandbox/emails/send', ['to' => 'me@elsewhere.test', 'slug' => 'reminder-day-5'], $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('sent', 1);
        Mail::assertSent(PixelMail::class, fn (PixelMail $mail) => $mail->hasTo('me@elsewhere.test'));
    }

    public function test_a_preview_may_go_to_a_team_domain(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);

        $this->postJson('/api/admin/sandbox/emails/send', ['to' => 'Dmitri@Feature-Digital.com', 'slug' => 'reminder-day-5'], $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('sent', 1);
        Mail::assertSent(PixelMail::class, fn (PixelMail $mail) => $mail->hasTo('Dmitri@Feature-Digital.com'));
    }

    /** A dozen emails from support@ to any address would make an admin login a spam relay. */
    public function test_a_preview_to_an_outside_address_is_refused(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);

        $this->postJson('/api/admin/sandbox/emails/send', ['to' => 'victim@example.test'], $this->headers($admin))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'RECIPIENT_NOT_ALLOWED');
        // A look-alike domain is an outside address too.
        $this->postJson('/api/admin/sandbox/emails/send', ['to' => 'victim@pixel-alpha.com.evil.test'], $this->headers($admin))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'RECIPIENT_NOT_ALLOWED');
        Mail::assertNothingSent();
    }
}
