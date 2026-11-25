<?php

namespace Tests\Feature;

use App\Mail\AccountApproved;
use App\Mail\EmailVerificationCode;
use App\Mail\NewRegistrationNotice;
use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * The account-lifecycle emails: the admin's "someone is waiting" notice once a
 * sign-up has verified their email (EmailVerificationTest covers the code
 * itself), and the customer's "you're approved" on acceptance.
 *
 * What matters here is that they are NOTIFICATIONS, not steps: neither a
 * missing admin address nor a dead SMTP host may take down a registration or
 * an approval, because the row is the fact and the mail is only the telling.
 */
class AccountMailTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['mail.admin_address' => 'desk@pixel-alpha.test']);
    }

    private function register(array $overrides = [])
    {
        return $this->postJson('/api/auth/register', array_merge([
            'name' => 'Jonathan Meyer',
            'email' => 'jonathan@example.test',
            'password' => 'super-secret-1',
            'password_confirmation' => 'super-secret-1',
            'terms' => true,
            'telegram' => 'pixel_trader',
            'whatsapp' => '+66812345678',
        ], $overrides));
    }

    /** Register, then type the code the API stored — the notice is sent on verify. */
    private function registerAndVerify(array $overrides = [])
    {
        $token = $this->register($overrides)->assertStatus(201)->json('token');
        $code = DB::table('user_credentials')->where('email', $overrides['email'] ?? 'jonathan@example.test')->value('verification_code');

        return $this->postJson('/api/auth/email/verify', ['code' => $code], ['Authorization' => 'Bearer '.$token]);
    }

    private function adminHeaders(string $uniId): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.UserCredential::find($uniId)->createToken('spa')->plainTextToken];
    }

    public function test_a_verified_registration_notifies_the_admin_desk_with_the_facts_it_needs(): void
    {
        $this->registerAndVerify()->assertOk();

        Mail::assertSent(NewRegistrationNotice::class, function (NewRegistrationNotice $mail) {
            return $mail->hasTo('desk@pixel-alpha.test')
                && $mail->name === 'Jonathan Meyer'
                && $mail->email === 'jonathan@example.test'
                && $mail->via === 'Email + password'
                // The row id, so the admin can find exactly this account.
                && $mail->uniId !== '';
        });
    }

    public function test_the_notice_carries_the_signups_ip_country_telegram_and_whatsapp(): void
    {
        // Registered from Thailand…
        $token = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeaders(['CF-IPCountry' => 'TH'])
            ->postJson('/api/auth/register', [
                'name' => 'Jonathan Meyer',
                'email' => 'jonathan@example.test',
                'password' => 'super-secret-1',
                'password_confirmation' => 'super-secret-1',
                'terms' => true,
                'telegram' => '@pixel_trader',
                'whatsapp' => '+66 81 234 5678',
            ])->assertStatus(201)->json('token');
        $code = DB::table('user_credentials')->where('email', 'jonathan@example.test')->value('verification_code');

        // …then typed the code from somewhere else. The notice is sent NOW,
        // but the IP and country it names must be the REGISTRATION's.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->withHeaders(['CF-IPCountry' => 'DE', 'Authorization' => 'Bearer '.$token])
            ->postJson('/api/auth/email/verify', ['code' => $code])
            ->assertOk();

        Mail::assertSent(NewRegistrationNotice::class, function (NewRegistrationNotice $mail) {
            $html = $mail->render();

            return $mail->ip === '203.0.113.7'
                && $mail->location === 'Thailand (TH)'
                && $mail->telegram === 'pixel_trader'
                && $mail->whatsapp === '+66812345678'
                && str_contains($html, '203.0.113.7')
                && ! str_contains($html, '198.51.100.9')
                && str_contains($html, 'Thailand (TH)')
                && str_contains($html, 'https://t.me/pixel_trader')
                && str_contains($html, '@pixel_trader')
                && str_contains($html, 'https://wa.me/66812345678')
                && str_contains($html, 'jonathan@example.test');
        });
    }

    public function test_the_notice_says_so_when_a_detail_is_not_on_record(): void
    {
        // A sign-up with no access row (it predates the log, or its insert
        // failed) and no contact row: the notice still goes, saying what is
        // missing rather than printing blanks.
        $uni = $this->makeUser(['status' => 'pending'], withContacts: false);

        app(\App\Services\Notifications\AccountMail::class)
            ->registrationPending(UserCredential::find($uni), 'Email + password');

        Mail::assertSent(NewRegistrationNotice::class, function (NewRegistrationNotice $mail) {
            $html = $mail->render();

            return $mail->ip === null && $mail->location === null
                && $mail->telegram === null && $mail->whatsapp === null
                && str_contains($html, 'Not recorded')
                && str_contains($html, 'Not known')
                && substr_count($html, 'Not given') === 2;
        });
    }

    public function test_a_country_code_reads_as_a_place(): void
    {
        $this->assertSame('Thailand (TH)', \App\Services\Auth\AccessLog::location('TH'));
        $this->assertSame('Philippines (PH)', \App\Services\Auth\AccessLog::location('PH'));
        // Cloudflare's code for Tor, which is not a country.
        $this->assertSame('Tor network (T1)', \App\Services\Auth\AccessLog::location('T1'));
        $this->assertNull(\App\Services\Auth\AccessLog::location(null));
    }

    public function test_the_notice_goes_to_the_desk_and_never_to_the_person_who_registered(): void
    {
        $this->registerAndVerify()->assertOk();

        Mail::assertSent(NewRegistrationNotice::class, fn (NewRegistrationNotice $mail) => ! $mail->hasTo('jonathan@example.test'));
        // Registering is not being approved: the welcome mail waits for an admin.
        Mail::assertNotSent(AccountApproved::class);
    }

    public function test_the_notice_goes_to_every_address_on_the_team_list(): void
    {
        // support@ plus a teammate; a junk entry is dropped, not fatal.
        config(['mail.admin_address' => 'desk@pixel-alpha.test, partner@example.test ,not-an-address']);

        $this->registerAndVerify()->assertOk();

        Mail::assertSent(NewRegistrationNotice::class, fn (NewRegistrationNotice $mail) => $mail->hasTo('desk@pixel-alpha.test')
            && $mail->hasTo('partner@example.test')
            && ! $mail->hasTo('not-an-address'));
    }

    public function test_registration_still_succeeds_when_no_admin_address_is_configured(): void
    {
        config(['mail.admin_address' => null]);

        $this->registerAndVerify()->assertOk();

        // Only the sign-up's own code went out; no team notice had anywhere to go.
        Mail::assertNotSent(NewRegistrationNotice::class);
        Mail::assertSent(EmailVerificationCode::class, fn (EmailVerificationCode $mail) => $mail->hasTo('jonathan@example.test'));
        $this->assertDatabaseHas('user_credentials', ['email' => 'jonathan@example.test', 'status' => 'pending']);
    }

    public function test_a_dead_mail_transport_cannot_fail_a_registration(): void
    {
        // Past the fake, into the failure a real SMTP host produces.
        Mail::shouldReceive('to')->andThrow(new RuntimeException('Connection could not be established'));

        $this->register()->assertStatus(201);

        $this->assertDatabaseHas('user_credentials', ['email' => 'jonathan@example.test']);
    }

    public function test_approving_a_pending_user_emails_them(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $pending = $this->makeUser(['status' => 'pending', 'name' => 'Jonathan', 'email' => 'jonathan@example.test']);

        $this->postJson("/api/admin/users/{$pending}/accept", [], $this->adminHeaders($admin))->assertOk();

        Mail::assertSent(AccountApproved::class, function (AccountApproved $mail) {
            return $mail->hasTo('jonathan@example.test') && $mail->name === 'Jonathan';
        });
    }

    public function test_rejecting_a_pending_user_emails_nothing(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $pending = $this->makeUser(['status' => 'pending', 'email' => 'jonathan@example.test']);

        $this->postJson("/api/admin/users/{$pending}/reject", [], $this->adminHeaders($admin))->assertOk();

        Mail::assertNothingSent();
    }

    public function test_a_dead_mail_transport_cannot_fail_an_approval(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $pending = $this->makeUser(['status' => 'pending', 'email' => 'jonathan@example.test']);

        Mail::shouldReceive('to')->andThrow(new RuntimeException('Connection could not be established'));

        $this->postJson("/api/admin/users/{$pending}/accept", [], $this->adminHeaders($admin))->assertOk();

        // The status change is the fact; the email is only the telling.
        $this->assertDatabaseHas('user_credentials', ['uni_id' => $pending, 'status' => 'active']);
    }
}
