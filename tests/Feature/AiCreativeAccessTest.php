<?php

namespace Tests\Feature;

use App\Models\AiAccessKey;
use App\Models\CreativeGeneration;
use App\Models\CreativePlan;
use App\Models\UserCredential;
use App\Services\Ai\AiAccessKeys;
use App\Services\Ai\Tools\CreativeMakeTools;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Grok and Admin → Creatives (owner, 2026-11-17): every key READS Creatives;
 * only a key a full admin switched on may price / start an image or clip and
 * save an Idea plan — two steps, one start per quote, under per-generation,
 * per-key-per-day and monthly limits, everything labelled "Grok".
 */
class AiCreativeAccessTest extends EngineTestCase
{
    private AiAccessKey $key;

    private string $plain;

    private int $submits = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-17 10:00:00', 'UTC'));
        Cache::flush();
        config([
            'services.higgsfield.key_id' => 'hf-test-id',
            'services.higgsfield.key_secret' => 'hf-test-secret',
            'services.higgsfield.base_url' => 'https://api.higgsfield.ai',
            'services.higgsfield.ca_bundle' => null,
        ]);
        [$this->key, $this->plain] = app(AiAccessKeys::class)->issue('Owner Grok', 90, null, 'Test Admin');

        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();
            if (str_starts_with($url, 'https://api.higgsfield.ai/estimate/')) {
                return str_contains($url, 'seedance') || str_contains($url, 'kling')
                    ? Http::response(['type' => 'description', 'pricing_description' => 'per video second'])
                    : Http::response(['type' => 'estimate', 'usd' => '0.004']);
            }
            if (preg_match('#^https://api\.higgsfield\.ai/requests/([^/]+)/status$#', $url, $m) === 1) {
                return Http::response(['status' => 'completed', 'request_id' => $m[1], 'images' => [['url' => 'https://cdn.higgsfield.test/img/'.$m[1].'.png']]]);
            }
            $n = ++$this->submits;

            return Http::response(['request_id' => "req-{$n}", 'status' => 'queued']);
        });
    }

    private function mcp(array $message, ?string $plain = null): TestResponse
    {
        return $this->postJson('/api/mcp', $message, ['X-Api-Key' => $plain ?? $this->plain]);
    }

    /** @return array{0: bool, 1: mixed} ok + the decoded answer (or the error text) */
    private function tool(string $tool, array $arguments = [], ?string $plain = null): array
    {
        $res = $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]], $plain)->assertOk();
        if ($res->json('error')) {
            return [false, $res->json('error.message')];
        }
        $text = $res->json('result.content.0.text');

        return $res->json('result.isError') ? [false, $text] : [true, json_decode($text, true)];
    }

    private function adminHeaders(string $type = 'admin'): array
    {
        $this->app['auth']->forgetGuards();
        $uni = $this->makeUser(['type' => $type, 'name' => 'Staff '.$type]);

        return ['Authorization' => 'Bearer '.UserCredential::find($uni)->createToken('spa')->plainTextToken];
    }

    private function allow(): void
    {
        $this->putJson("/api/admin/ai-access/keys/{$this->key->id}", ['can_create' => true], $this->adminHeaders())
            ->assertOk()->assertJsonPath('key.can_create', true)->assertJsonPath('key.can_create_changed_by_name', 'Staff admin');
    }

    public function test_the_make_tools_are_hidden_until_a_full_admin_switches_them_on(): void
    {
        $names = fn () => array_column($this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->json('result.tools'), 'name');
        $this->assertContains('creative_plans', $names());
        $this->assertNotContains('price_generation', $names());
        [$ok, $error] = $this->tool('price_generation', ['model' => 'soul-image', 'prompt' => 'Pixel waves']);
        $this->assertFalse($ok);
        $this->assertStringContainsString('Unknown tool', $error);
        $this->assertSame('Pixel Alpha (read-only)', $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])->json('result.serverInfo.title'));

        // A collaborator cannot switch it on; a full admin can.
        $this->putJson("/api/admin/ai-access/keys/{$this->key->id}", ['can_create' => true], $this->adminHeaders('collaborator'))->assertForbidden();
        $this->allow();

        $tools = collect($this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->json('result.tools'))->keyBy('name');
        foreach (['price_generation', 'start_generation', 'save_plan_idea'] as $tool) {
            $this->assertFalse($tools[$tool]['annotations']['readOnlyHint'], $tool);
        }
        $this->assertTrue($tools['creative_plans']['annotations']['readOnlyHint']);
        $init = $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])->json('result');
        $this->assertSame('Pixel Alpha', $init['serverInfo']['title']);
        $this->assertStringContainsString('start_generation', $init['instructions']);

        // A revoked key cannot be switched on.
        app(AiAccessKeys::class)->revoke($this->key, 'Test Admin');
        $this->putJson("/api/admin/ai-access/keys/{$this->key->id}", ['can_create' => true], $this->adminHeaders())
            ->assertStatus(422)->assertJsonPath('error_code', 'KEY_NOT_ACTIVE');
    }

    public function test_grok_prices_first_then_starts_that_quote_once_labelled_grok(): void
    {
        $this->allow();
        [$ok, $quote] = $this->tool('price_generation', ['model' => 'soul-image', 'prompt' => 'Pixel celebrates a green day, arcade style']);
        $this->assertTrue($ok, (string) json_encode($quote));
        $this->assertTrue($quote['can_start']);
        $this->assertSame(0.004, $quote['usd']);
        $this->assertSame(0, $this->submits, 'Pricing starts nothing.');

        [$ok, $started] = $this->tool('start_generation', ['quote_id' => $quote['quote_id']]);
        $this->assertTrue($ok, (string) json_encode($started));
        $this->assertSame(1, $this->submits);
        $generation = CreativeGeneration::sole();
        $this->assertSame('Grok', $generation->created_by_name);
        $this->assertNull($generation->created_by_uni_id);
        $this->assertSame($this->key->id, (int) $generation->ai_access_key_id);

        // The same quote never starts twice.
        [$ok, $error] = $this->tool('start_generation', ['quote_id' => $quote['quote_id']]);
        $this->assertFalse($ok);
        $this->assertStringContainsString('already used', $error);
        $this->assertSame(1, $this->submits);

        // Checking it: Higgsfield says done; the file comes with a signed download link.
        [$ok, $read] = $this->tool('creative_generations', ['generation_id' => $generation->id]);
        $this->assertTrue($ok);
        $this->assertSame('completed', $read['generation']['status']);
        $this->assertTrue($read['generation']['by_grok']);
        $this->assertSame('Grok', $read['generation']['started_by']);
        $this->assertStringContainsString('/api/creatives/generations/'.$generation->id.'/download/0', $read['generation']['files'][0]['download_url']);
        $this->assertStringContainsString('signature=', $read['generation']['files'][0]['download_url']);

        // The budget names Grok as a spender.
        [, $budget] = $this->tool('creative_budget');
        $this->assertContains('Grok', array_column($budget['budget']['by_person'] ?? [], 'name'));
    }

    public function test_another_keys_quote_is_refused(): void
    {
        $this->allow();
        [$ok, $quote] = $this->tool('price_generation', ['model' => 'soul-image', 'prompt' => 'Pixel at a desk']);
        $this->assertTrue($ok);
        [$other, $otherPlain] = app(AiAccessKeys::class)->issue('Second Grok', 90, null, 'Test Admin');
        $other->forceFill(['can_create' => true])->save();

        [$ok] = $this->tool('start_generation', ['quote_id' => $quote['quote_id']], $otherPlain);
        $this->assertFalse($ok);
        $this->assertSame(0, $this->submits);
    }

    public function test_the_per_generation_and_daily_limits_hold_before_anything_starts(): void
    {
        $this->allow();
        // Seedance 10 s at 720p ≈ $2.65 — over the $1 limit for one generation.
        [$ok, $quote] = $this->tool('price_generation', [
            'model' => 'seedance-text-video', 'prompt' => 'A pixel city at night, chiptune music',
            'params' => ['duration' => 10, 'resolution' => '720p'],
        ]);
        $this->assertTrue($ok, (string) json_encode($quote));
        $this->assertFalse($quote['can_start']);
        $this->assertNull($quote['quote_id']);
        $this->assertStringContainsString('$1.00', $quote['why_not']);

        // $19.999 already spent today on this key: even $0.004 would pass $20.
        CreativeGeneration::query()->forceCreate([
            'kind' => 'video', 'model' => 'kling-animate', 'endpoint' => 'x', 'prompt' => 'p', 'full_prompt' => 'p', 'params' => [],
            'used_character' => false, 'status' => 'completed', 'estimated_usd' => 19.999, 'charged_usd' => 19.999,
            'created_by_name' => 'Grok', 'ai_access_key_id' => $this->key->id,
        ]);
        [, $quote] = $this->tool('price_generation', ['model' => 'soul-image', 'prompt' => 'Pixel waves']);
        $this->assertFalse($quote['can_start']);
        $this->assertStringContainsString('daily limit', $quote['why_not']);
        $this->assertSame(0, $this->submits);
        $this->assertSame(CreativeMakeTools::MAX_PER_KEY_DAY_USD, 20.0);
    }

    public function test_nothing_private_goes_into_a_prompt_or_a_plan(): void
    {
        $this->allow();
        [$ok, $error] = $this->tool('price_generation', ['model' => 'soul-image', 'prompt' => 'A banner saying we made $2,500 this week']);
        $this->assertFalse($ok);
        $this->assertStringContainsString('Not allowed', $error);

        [$ok, $error] = $this->tool('save_plan_idea', ['title' => 'Bot recap', 'caption' => 'Our customers earned $12,000 in October']);
        $this->assertFalse($ok);
        $this->assertStringContainsString('Not allowed', $error);
        $this->assertSame(0, CreativePlan::count());
    }

    public function test_grok_saves_an_idea_plan_the_team_sees(): void
    {
        $this->allow();
        [$ok, $saved] = $this->tool('save_plan_idea', ['title' => 'Friday bot recap', 'platform' => 'tiktok', 'hook' => 'Pixel levels up']);
        $this->assertTrue($ok, (string) json_encode($saved));
        $plan = CreativePlan::sole();
        $this->assertSame('idea', $plan->status);
        $this->assertSame('Grok', $plan->created_by_name);

        [, $plans] = $this->tool('creative_plans', ['status' => 'idea']);
        $this->assertSame('Friday bot recap', $plans['plans'][0]['title']);
        $this->assertSame('Grok', $plans['plans'][0]['created_by_name']);

        // A key not switched on can read it but never write one.
        [, $readOnlyPlain] = app(AiAccessKeys::class)->issue('Reader', 90, null, 'Test Admin');
        [$ok] = $this->tool('save_plan_idea', ['title' => 'Sneaky'], $readOnlyPlain);
        $this->assertFalse($ok);
        [$ok] = $this->tool('creative_plans', [], $readOnlyPlain);
        $this->assertTrue($ok);
    }

    public function test_the_read_tools_answer_without_a_switched_on_key(): void
    {
        foreach (['creative_plans', 'creative_videos', 'creative_generations', 'creative_budget', 'creative_setup', 'creative_models', 'creative_chats'] as $tool) {
            [$ok, $answer] = $this->tool($tool);
            $this->assertTrue($ok, "{$tool}: ".json_encode($answer));
        }
        [$ok, $error] = $this->tool('creative_video', ['video_id' => 999]);
        $this->assertFalse($ok);
        $this->assertStringContainsString('creative_videos', $error);
    }
}
