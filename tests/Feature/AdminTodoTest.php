<?php

namespace Tests\Feature;

use App\Models\UserCredential;

/**
 * Admin → To be Done: the done-state store behind the owner's list.
 */
class AdminTodoTest extends EngineTestCase
{
    private function headers(string $uniId): array
    {
        $user = UserCredential::where('uni_id', $uniId)->firstOrFail();
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_starts_empty(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);

        $this->getJson('/api/admin/todos', $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertExactJson(['success' => true, 'states' => []]);
    }

    public function test_mark_done_records_who_and_when_and_reopen_keeps_the_note(): void
    {
        $admin = $this->makeUser(['type' => 'admin', 'name' => 'Owner']);

        $this->putJson('/api/admin/todos/discord-decide-roles', [
            'done' => true,
            'note' => 'Member + Trader, Trader only for live keys.',
        ], $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('state.done_by', $admin)
            ->assertJsonPath('state.done_by_name', 'Owner')
            ->assertJsonPath('state.note', 'Member + Trader, Trader only for live keys.');

        $listed = $this->getJson('/api/admin/todos', $this->headers($admin))->assertOk();
        $this->assertNotNull($listed->json('states.discord-decide-roles.done_at'));

        $this->putJson('/api/admin/todos/discord-decide-roles', ['done' => false], $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('state.done_at', null)
            ->assertJsonPath('state.done_by', null)
            ->assertJsonPath('state.note', 'Member + Trader, Trader only for live keys.');
    }

    public function test_note_alone_does_not_tick_the_item_and_blank_clears_it(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);

        $this->putJson('/api/admin/todos/tron-nile-wallet', ['note' => 'waiting on funds'], $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('state.done_at', null)
            ->assertJsonPath('state.note', 'waiting on funds');

        $this->putJson('/api/admin/todos/tron-nile-wallet', ['note' => '   '], $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('state.note', null);
    }

    public function test_marking_done_twice_keeps_the_first_stamp(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);
        $other = $this->makeUser(['type' => 'admin']);

        $first = $this->putJson('/api/admin/todos/x-y', ['done' => true], $this->headers($admin))->json('state');
        $this->travel(1)->hour();
        $second = $this->putJson('/api/admin/todos/x-y', ['done' => true], $this->headers($other))->json('state');

        $this->assertSame($first['done_at'], $second['done_at']);
        $this->assertSame($admin, $second['done_by']);
    }

    public function test_bad_slugs_and_long_notes_are_refused(): void
    {
        $admin = $this->makeUser(['type' => 'admin']);

        $this->putJson('/api/admin/todos/Not_A_Slug', ['done' => true], $this->headers($admin))->assertNotFound();
        $this->putJson('/api/admin/todos/'.str_repeat('a', 81), ['done' => true], $this->headers($admin))->assertNotFound();
        $this->putJson('/api/admin/todos/ok-slug', ['note' => str_repeat('n', 4001)], $this->headers($admin))
            ->assertStatus(422);
    }

    public function test_plain_users_cannot_see_or_touch_it(): void
    {
        $user = $this->makeUser();

        $this->getJson('/api/admin/todos', $this->headers($user))->assertForbidden();
        $this->putJson('/api/admin/todos/ok-slug', ['done' => true], $this->headers($user))->assertForbidden();
    }
}
