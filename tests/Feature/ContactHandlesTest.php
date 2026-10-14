<?php

namespace Tests\Feature;

use App\Models\UserCredential;
use App\Services\Signup\ContactHandles;
use Illuminate\Support\Facades\DB;

/**
 * GET / PUT /api/user/contacts — Settings → the Telegram / Discord usernames.
 *
 * PUT is a partial update with one rule a client must be able to rely on: an
 * ABSENT key leaves that handle alone, null or '' clears it. Values go
 * through the same normalisation and username rules as the sign-up form, and
 * a user with neither handle has no row at all.
 */
class ContactHandlesTest extends EngineTestCase
{
    private string $uniId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uniId = $this->makeUser();
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

    public function test_get_answers_nulls_when_nothing_was_given(): void
    {
        $this->getJson('/api/user/contacts', $this->headers())
            ->assertOk()
            ->assertExactJson(['success' => true, 'contacts' => ['telegram' => null, 'discord' => null]]);
    }

    public function test_put_sets_normalised_handles_and_get_reads_them_back(): void
    {
        $this->save(['telegram' => 'https://t.me/Pixel_Alpha', 'discord' => '@Foo.Bar'])
            ->assertOk()
            ->assertExactJson(['success' => true, 'contacts' => ['telegram' => 'Pixel_Alpha', 'discord' => 'foo.bar']]);

        $this->getJson('/api/user/contacts', $this->headers())
            ->assertOk()
            ->assertJsonPath('contacts.telegram', 'Pixel_Alpha')
            ->assertJsonPath('contacts.discord', 'foo.bar');
    }

    public function test_an_absent_key_is_unchanged_and_null_or_blank_clears(): void
    {
        $this->save(['telegram' => '@first_one', 'discord' => 'first.one'])->assertOk();

        // Only Discord sent: Telegram stays.
        $this->save(['discord' => 'second.one'])
            ->assertOk()
            ->assertJsonPath('contacts.telegram', 'first_one')
            ->assertJsonPath('contacts.discord', 'second.one');

        // Telegram cleared with null: Discord stays.
        $this->save(['telegram' => null])
            ->assertOk()
            ->assertJsonPath('contacts.telegram', null)
            ->assertJsonPath('contacts.discord', 'second.one');

        // Nothing sent: nothing moves.
        $this->save([])
            ->assertOk()
            ->assertJsonPath('contacts.telegram', null)
            ->assertJsonPath('contacts.discord', 'second.one');

        // Discord cleared with '': both empty, so the row goes.
        $this->save(['discord' => ''])
            ->assertOk()
            ->assertExactJson(['success' => true, 'contacts' => ['telegram' => null, 'discord' => null]]);

        $this->assertSame(0, DB::table('user_contact_handles')->count());
    }

    public function test_an_invalid_handle_is_a_422_and_changes_nothing(): void
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

    public function test_handles_are_not_unique_across_users(): void
    {
        $this->save(['telegram' => 'same_name'])->assertOk();

        $other = $this->makeUser();
        $this->putJson('/api/user/contacts', ['telegram' => 'same_name'], $this->headers($other))
            ->assertOk()
            ->assertJsonPath('contacts.telegram', 'same_name');
    }

    public function test_each_user_sees_only_their_own(): void
    {
        $this->save(['telegram' => 'mine_only'])->assertOk();

        $other = $this->makeUser();
        $this->getJson('/api/user/contacts', $this->headers($other))
            ->assertOk()
            ->assertJsonPath('contacts.telegram', null);
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
