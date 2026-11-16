<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use App\Services\Signup\ContactHandles;
use Illuminate\Support\Facades\DB;

/**
 * GET / PUT /api/user/contacts — Settings → Telegram, Discord, WhatsApp.
 *
 * PUT is a partial update with one rule a client must be able to rely on: an
 * ABSENT key leaves that value alone. Discord is optional (null or '' clears
 * it); Telegram and WhatsApp are required since 2026-11-16, so they can be
 * changed but not cleared. GET also says which required ones are missing and
 * whether that locks connecting an exchange (customers only).
 */
class ContactHandlesTest extends EngineTestCase
{
    private string $uniId;

    protected function setUp(): void
    {
        parent::setUp();
        // An existing customer from before the rule: nothing given yet.
        $this->uniId = $this->makeUser([], withContacts: false);
    }

    private function headers(?string $uniId = null): array
    {
        $this->app['auth']->forgetGuards();
        $token = UserCredential::find($uniId ?? $this->uniId)->createToken('spa')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token];
    }

    private function save(array $body)
    {
        return $this->putJson('/api/user/contacts', $body, $this->headers());
    }

    public function test_get_names_what_is_missing_and_locks_connecting(): void
    {
        $this->getJson('/api/user/contacts', $this->headers())
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'contacts' => ['telegram' => null, 'discord' => null, 'whatsapp' => null],
                'missing' => ['telegram', 'whatsapp'],
                'connect_locked' => true,
            ]);
    }

    public function test_put_sets_normalised_values_and_unlocks_once_both_are_there(): void
    {
        $this->save(['telegram' => 'https://t.me/Pixel_Alpha', 'discord' => '@Foo.Bar'])
            ->assertOk()
            ->assertJsonPath('contacts.telegram', 'Pixel_Alpha')
            ->assertJsonPath('contacts.discord', 'foo.bar')
            ->assertJsonPath('missing', ['whatsapp'])
            ->assertJsonPath('connect_locked', true);

        $this->save(['whatsapp' => '+66 81-234 5678'])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'contacts' => ['telegram' => 'Pixel_Alpha', 'discord' => 'foo.bar', 'whatsapp' => '+66812345678'],
                'missing' => [],
                'connect_locked' => false,
            ]);
    }

    public function test_whatsapp_numbers_normalise_to_e164(): void
    {
        foreach ([
            '+66 81 234 5678' => '+66812345678',
            '0066812345678' => '+66812345678',
            '+1 (415) 555-0123' => '+14155550123',
            'https://wa.me/639171234567' => '+639171234567',
            'wa.me/639171234567' => '+639171234567',
        ] as $typed => $stored) {
            $this->save(['whatsapp' => $typed])->assertOk()->assertJsonPath('contacts.whatsapp', $stored);
        }
    }

    public function test_a_number_without_a_country_code_is_refused(): void
    {
        foreach (['0812345678', '812345678', '+0812345678', '+66 81', 'call me', '+6681234567890123'] as $bad) {
            $this->save(['whatsapp' => $bad])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['whatsapp' => ContactHandles::WHATSAPP_MESSAGE]);
        }
        $this->assertSame(0, DB::table('user_contact_handles')->count());
    }

    public function test_an_absent_key_is_unchanged_and_the_required_two_cannot_be_cleared(): void
    {
        $this->save(['telegram' => '@first_one', 'whatsapp' => '+66812345678', 'discord' => 'first.one'])->assertOk();

        // Only Discord sent: the others stay.
        $this->save(['discord' => 'second.one'])
            ->assertOk()
            ->assertJsonPath('contacts.telegram', 'first_one')
            ->assertJsonPath('contacts.whatsapp', '+66812345678')
            ->assertJsonPath('contacts.discord', 'second.one');

        // Clearing a required one is refused, with or without a value beside it.
        $this->save(['telegram' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['telegram' => ContactHandles::TELEGRAM_REQUIRED]);
        $this->save(['whatsapp' => '', 'discord' => 'third.one'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['whatsapp' => ContactHandles::WHATSAPP_REQUIRED]);

        // Nothing sent: nothing moves.
        $this->save([])->assertOk()->assertJsonPath('contacts.discord', 'second.one');

        // Discord is optional: '' clears it.
        $this->save(['discord' => ''])
            ->assertOk()
            ->assertJsonPath('contacts.discord', null)
            ->assertJsonPath('contacts.telegram', 'first_one');
    }

    public function test_an_invalid_value_is_a_422_and_changes_nothing(): void
    {
        $this->save(['telegram' => 'good_name', 'discord' => 'good.name'])->assertOk();

        $this->save(['telegram' => 'no', 'discord' => 'fine.name'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['telegram' => ContactHandles::TELEGRAM_MESSAGE])
            ->assertJsonMissingValidationErrors(['discord']);

        $this->save(['discord' => 'Has Space'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['discord' => ContactHandles::DISCORD_MESSAGE]);

        $this->save(['telegram' => ['array']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['telegram' => ContactHandles::TELEGRAM_MESSAGE]);

        $this->assertDatabaseHas('user_contact_handles', [
            'uni_id' => $this->uniId,
            'telegram' => 'good_name',
            'discord' => 'good.name',
        ]);
    }

    public function test_values_are_not_unique_across_users(): void
    {
        $this->save(['telegram' => 'same_name', 'whatsapp' => '+66812345678'])->assertOk();

        $other = $this->makeUser([], withContacts: false);
        $this->putJson('/api/user/contacts', ['telegram' => 'same_name', 'whatsapp' => '+66812345678'], $this->headers($other))
            ->assertOk()
            ->assertJsonPath('contacts.telegram', 'same_name')
            ->assertJsonPath('contacts.whatsapp', '+66812345678');
    }

    public function test_each_user_sees_only_their_own(): void
    {
        $this->save(['telegram' => 'mine_only'])->assertOk();

        $other = $this->makeUser([], withContacts: false);
        $this->getJson('/api/user/contacts', $this->headers($other))
            ->assertOk()
            ->assertJsonPath('contacts.telegram', null);
    }

    public function test_staff_are_never_locked_out_of_connecting(): void
    {
        foreach (['admin', 'master', 'developer', 'tester', 'collaborator'] as $type) {
            $staff = $this->makeUser(['type' => $type], withContacts: false);
            $this->getJson('/api/user/contacts', $this->headers($staff))
                ->assertOk()
                ->assertJsonPath('missing', ['telegram', 'whatsapp'])
                ->assertJsonPath('connect_locked', false);
        }
    }

    public function test_signed_out_and_unverified_are_refused(): void
    {
        $this->getJson('/api/user/contacts')->assertUnauthorized();
        $this->putJson('/api/user/contacts', ['telegram' => 'whoever'])->assertUnauthorized();

        $unverified = $this->makeUser(['email_verified' => 0]);
        $this->getJson('/api/user/contacts', $this->headers($unverified))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'EMAIL_UNVERIFIED');
    }
}
