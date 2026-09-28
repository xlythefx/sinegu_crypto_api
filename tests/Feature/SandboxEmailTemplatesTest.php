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

        $this->postJson('/api/admin/sandbox/emails/send', ['to' => 'reviewer@example.test', 'slug' => 'reminder-day-5'], $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('sent', 1);

        Mail::assertSent(PixelMail::class, fn (PixelMail $mail) => $mail->hasTo('reviewer@example.test')
            && str_starts_with((string) $mail->envelope()->subject, '[Preview] '));
    }

    public function test_send_all_mails_every_template_to_the_reviewer_only(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);

        $this->postJson('/api/admin/sandbox/emails/send', ['to' => 'reviewer@example.test'], $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('sent', count(EmailCatalogue::all()));

        Mail::assertSentCount(count(EmailCatalogue::all()));
        // Sample data names a customer; nothing may go to that address.
        Mail::assertNotSent(PixelMail::class, fn (PixelMail $mail) => $mail->hasTo('jonathan.meyer@example.com'));
    }

    public function test_an_unknown_template_is_a_404(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);

        $this->postJson('/api/admin/sandbox/emails/send', ['to' => 'reviewer@example.test', 'slug' => 'nope'], $this->headers($admin))
            ->assertNotFound();
        Mail::assertNothingSent();
    }
}
