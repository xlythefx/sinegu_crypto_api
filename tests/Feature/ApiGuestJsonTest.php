<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A guest calling a signed-in API route WITHOUT `Accept: application/json`
 * (curl, a probe, a script) must get the same 401 JSON the SPA gets — never a
 * 500 from Laravel trying to redirect it to a `login` route this API does not have.
 */
class ApiGuestJsonTest extends TestCase
{
    public function test_a_guest_without_a_json_accept_header_gets_401_json(): void
    {
        $this->get('/api/auth/me')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/json');

        $this->get('/api/user/contacts')->assertStatus(401);
    }

    public function test_a_guest_asking_for_json_still_gets_401(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }
}
