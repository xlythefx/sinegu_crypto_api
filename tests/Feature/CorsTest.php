<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * config/cors.php — which browser origins may call /api/*.
 *
 * Without the file Laravel's own default applies, allowed_origins ['*'].
 * Prod serves the SPA and the API from one origin, so there it is belt and
 * braces; it is load-bearing on a developer's box and against a page on
 * another site driving the API from a visitor's browser. Credentials are
 * never shared — the token travels in a header, not a cookie.
 */
class CorsTest extends TestCase
{
    use RefreshDatabase;

    /** The shipped default list — more than one entry on purpose: php-cors echoes a lone origin unconditionally. */
    private const ALLOWED = [
        'https://pixel-alpha.com',
        'https://www.pixel-alpha.com',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Pinned, so a CORS_ALLOWED_ORIGINS in a local .env cannot change what
        // this test measures. HandleCors re-reads config on every request.
        config(['cors.allowed_origins' => self::ALLOWED]);
    }

    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/auth/login', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);
    }

    public function test_a_preflight_from_an_allowed_origin_is_answered_with_that_origin(): void
    {
        foreach (['http://localhost:5173', 'https://pixel-alpha.com'] as $origin) {
            $response = $this->preflight($origin);
            $response->assertNoContent();
            $response->assertHeader('Access-Control-Allow-Origin', $origin);
            // Bearer tokens, not cookies: nothing to share cross-site.
            $response->assertHeaderMissing('Access-Control-Allow-Credentials');
        }
    }

    public function test_a_preflight_from_another_origin_gets_no_allow_origin(): void
    {
        $this->preflight('https://evil.example')->assertHeaderMissing('Access-Control-Allow-Origin');
        // A look-alike is another origin too.
        $this->preflight('https://pixel-alpha.com.evil.example')->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_an_actual_request_is_scoped_the_same_way(): void
    {
        $this->postJson('/api/auth/login', [], ['Origin' => 'http://localhost:5173'])
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
        $this->postJson('/api/auth/login', [], ['Origin' => 'https://evil.example'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    /** The file as shipped (not the test override): the product's origins, no wildcard, no credentials. */
    public function test_the_shipped_defaults_name_the_product_origins_and_no_wildcard(): void
    {
        $config = require base_path('config/cors.php');

        $this->assertSame(['api/*'], $config['paths']);
        $this->assertFalse($config['supports_credentials']);
        $this->assertNotContains('*', $config['allowed_origins']);
        $this->assertContains('https://pixel-alpha.com', $config['allowed_origins']);
        $this->assertContains('http://localhost:5173', $config['allowed_origins']);
    }
}
