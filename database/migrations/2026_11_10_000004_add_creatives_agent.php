<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → Creatives, the chat agent (owner's request, 2026-11-10): the
 * marketing team talks to Claude in an Ask mode (discuss, read, remember) and
 * a Generate mode (it plans posts and PROPOSES paid jobs; a person clicks Go).
 *
 *  - creative_agent_sessions — one chat, owned by the person who started it.
 *    Only the current conversation matters: sessions idle for 7 days are
 *    pruned (`creatives:agent-prune`), messages and jobs with them.
 *  - creative_agent_messages — the conversation as sent to Claude. `content`
 *    is the EXACT API content blocks (thinking blocks included) and is
 *    replayed unchanged on every later call: the history is append-only.
 *    It is a TEXT column holding the JSON verbatim, not a JSON column —
 *    MySQL's JSON type re-orders object keys, and replayed bytes must be the
 *    bytes Claude returned. `display` is what the page shows (no thinking,
 *    no tool blocks: one activity line per tool call). A `notice` row is
 *    display-only (content NULL) and never sent.
 *  - creative_agent_jobs — what the agent proposed in Generate mode: one or
 *    more priced steps, started only when a person clicks Go, each step a
 *    normal Higgsfield generation (creative_generations.agent_job_id).
 *  - creative_memories — team-wide notes the agent always reads.
 *  - creative_settings += the brand brief (Markdown), seeded below.
 *
 * Its own migration (000002/000003 are live on prod), so
 * `migrate:rollback --step=1` removes the agent and leaves the rest standing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_agent_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('uni_id', 64)->comment('The person whose chat it is; nobody else can open it');
            $table->string('user_name', 120)->nullable();
            $table->string('last_mode', 8)->default('ask')->comment('ask | generate');
            $table->json('sent_context')->nullable()->comment('Versions of the brief / memory already sent in this chat');
            $table->timestamps();

            $table->index('uni_id', 'idx_creative_agent_sessions_uni');
            $table->index('updated_at', 'idx_creative_agent_sessions_updated');
        });

        Schema::create('creative_agent_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('session_id')->constrained('creative_agent_sessions')->cascadeOnDelete();
            $table->string('role', 12)->comment('user | assistant | notice (display-only)');
            $table->longText('content')->nullable()->comment('EXACT API content blocks as JSON text — replayed byte for byte');
            $table->json('display')->nullable()->comment('What the page shows for this message');
            $table->string('mode', 8)->nullable();
            $table->string('stop_reason', 24)->nullable()->comment('Assistant rows: Claude\'s stop reason');
            $table->timestamp('created_at')->nullable();

            $table->index(['session_id', 'id'], 'idx_creative_agent_messages_session');
        });

        Schema::create('creative_agent_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('session_id')->constrained('creative_agent_sessions')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('creative_plans')->nullOnDelete();
            $table->string('title', 160);
            $table->string('status', 12)->default('proposed')->comment('proposed | running | completed | failed | canceled | expired');
            $table->json('steps');
            $table->decimal('estimated_usd', 10, 4)->comment('Sum of the steps\' price checks at proposal');
            $table->string('approved_by_name', 120)->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index('status', 'idx_creative_agent_jobs_status');
        });

        Schema::table('creative_generations', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_job_id')->nullable()->after('id')
                ->comment('The chat agent\'s job this generation is a step of');
            $table->index('agent_job_id', 'idx_creative_generations_agent_job');
        });

        Schema::create('creative_memories', function (Blueprint $table) {
            $table->id();
            $table->string('text', 500);
            $table->string('category', 16)->default('other')->comment('brand | audience | performance | process | other');
            $table->string('source', 8)->comment('agent | person');
            $table->string('created_by_name', 120)->nullable();
            $table->string('updated_by_name', 120)->nullable();
            $table->timestamps();
        });

        Schema::table('creative_settings', function (Blueprint $table) {
            $table->longText('brief_markdown')->nullable()->after('ai_weekly_recap')
                ->comment('The brand brief the chat agent always follows (Markdown)');
            $table->string('brief_updated_by_name', 120)->nullable()->after('brief_markdown');
            $table->dateTime('brief_updated_at')->nullable()->after('brief_updated_by_name');
        });

        DB::table('creative_settings')->where('id', 1)->update(['brief_markdown' => self::BRIEF]);
    }

    public function down(): void
    {
        Schema::table('creative_settings', function (Blueprint $table) {
            $table->dropColumn(['brief_markdown', 'brief_updated_by_name', 'brief_updated_at']);
        });

        Schema::dropIfExists('creative_memories');

        Schema::table('creative_generations', function (Blueprint $table) {
            $table->dropIndex('idx_creative_generations_agent_job');
            $table->dropColumn('agent_job_id');
        });

        Schema::dropIfExists('creative_agent_jobs');
        Schema::dropIfExists('creative_agent_messages');
        Schema::dropIfExists('creative_agent_sessions');
    }

    /** The starting brief — plain, editable, and without a single figure. */
    private const BRIEF = <<<'MD'
# Pixel Alpha — brand brief

## Who we are
Pixel Alpha is an automated crypto trading bot. People connect their own Binance, Bybit or MEXC account with trade-only API keys: the bot can place trades, it can never withdraw. There is no subscription — Pixel Alpha only earns a share of the profit it makes, and nothing when there is none. Site: pixel-alpha.com.

## Who we talk to
- People who already hold some crypto and are curious about automation, but do not want to watch charts all day.
- Beginners who need it explained simply and honestly, without hype.
- Traders who care about transparency: published results, clear fees, their money staying in their own account.

## Voice
- Friendly, plain and confident. Short sentences. Explain, don't shout.
- Honest about risk: crypto trading can lose money, and results change from week to week.
- A little playful through Pixel, never silly about money.

## Pixel
Pixel is our pixel-art robot mascot and appears in every creative. Keep the look exactly as the Character panel describes it — same design, same colours, every time. Pixel is curious, calm and helpful: it explains things, it celebrates the work, it never brags.

## Always / Never
**Always**
- Use only the published, public results — copied exactly — and add "Past performance does not guarantee future results." to any caption that cites a percentage.
- Make the first second count on TikTok: a clear hook and on-screen text.
- Say "trade-only keys" and "your money stays in your account" when explaining how it works.

**Never**
- Promise or hint at future returns ("guaranteed", "passive income", "can't lose").
- Show money amounts, balances, how many users we have, or any real person.
- Use other brands' logos, or copy another creator's content.

## Formats per platform
- **TikTok:** vertical 9:16, 15–30 seconds, hook in the first 2 seconds, quick cuts, on-screen text, a caption with 3–8 hashtags.
- **X:** one idea per post, caption under 280 characters including hashtags; 16:9 or 1:1 video, or a single image.

## Recipes
### Weekly recap video
1. A still of Pixel at a trading desk with a rising chart behind it (Character image, 9:16).
2. Animate that still for 10 seconds (Animate an image).
3. Caption: the week's public figures exactly as published, then the disclaimer line.

### Meet Pixel
A short intro to the mascot: a still of Pixel facing the camera, animated into a friendly 5-second wave. Caption: who Pixel is and what Pixel Alpha does, in one or two sentences.

### How the bot works in 20 seconds
Three stills, each animated into a short clip:
1. Pixel connects an exchange account with a key that can only trade.
2. Pixel watches the market while the person gets on with their day.
3. Pixel shows that Pixel Alpha only earns when the person profits.
Caption: the three steps in plain words, and a reminder that trading is risky.
MD;
};
