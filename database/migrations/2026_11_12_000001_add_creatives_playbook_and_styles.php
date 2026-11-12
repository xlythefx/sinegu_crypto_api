<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creatives v2, part 1 (owner-approved 2026-11-12): what the chat agent
 * follows when it makes a VIDEO.
 *
 *  - creative_settings += the video PLAYBOOK (Markdown) — the base every
 *    video follows, beside the brand brief, with its own "last edited by".
 *  - creative_styles — the library of marketing STYLES (data recap, pixel
 *    arcade, storytime, cinematic b-roll, fast cut…): the LOOK of a video.
 *    Rows, not repo files: the team edits them on the page without a deploy,
 *    and the repo is public. Seeded with five.
 *  - creative_agent_sessions += tools_version: the agent's tool list must
 *    stay byte-identical within one chat (preserved thinking), so a chat
 *    started before the tools changed is closed and a fresh one opened
 *    (CreativeAgentPrompt::TOOLS_VERSION).
 *
 * The seeds carry no money sign and no person's name — they are read by the
 * model in every chat and copied into posts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_settings', function (Blueprint $table) {
            $table->longText('playbook_markdown')->nullable()->after('brief_updated_at')
                ->comment('The video playbook the chat agent follows (Markdown)');
            $table->string('playbook_updated_by_name', 120)->nullable()->after('playbook_markdown');
            $table->dateTime('playbook_updated_at')->nullable()->after('playbook_updated_by_name');
        });

        Schema::create('creative_styles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 80);
            $table->string('summary', 200)->comment('One line the agent reads when choosing a style');
            $table->longText('markdown');
            $table->string('default_platform', 8)->default('any')->comment('x | tiktok | any');
            $table->string('default_aspect', 5)->default('9:16')->comment('9:16 | 16:9 | 1:1');
            $table->string('pixel_default', 8)->default('none')->comment('none | cameo | lead — how much mascot');
            $table->string('default_engine', 10)->default('kling')->comment('kling | seedance');
            $table->string('caption_style', 8)->default('bold')->comment('clean | bold | pixel');
            $table->string('template_family', 8)->default('clean')->comment('clean | pixel');
            $table->string('music_default', 20)->default('none')->comment('none | a chiptune preset');
            $table->boolean('pixel_look')->default(false)->comment('Pixelate + scanlines filter on by default');
            $table->boolean('enabled')->default(true);
            $table->smallInteger('sort')->default(0);
            $table->string('created_by_name', 120)->nullable();
            $table->string('updated_by_name', 120)->nullable();
            $table->timestamps();
        });

        Schema::table('creative_agent_sessions', function (Blueprint $table) {
            $table->unsignedSmallInteger('tools_version')->default(1)->after('sent_context')
                ->comment('The agent tool list this chat was started with');
        });

        DB::table('creative_settings')->where('id', 1)->update(['playbook_markdown' => self::PLAYBOOK]);

        $now = now();
        foreach (self::STYLES as $i => $style) {
            DB::table('creative_styles')->insert($style + [
                'enabled' => true,
                'sort' => ($i + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('creative_agent_sessions', function (Blueprint $table) {
            $table->dropColumn('tools_version');
        });

        Schema::dropIfExists('creative_styles');

        Schema::table('creative_settings', function (Blueprint $table) {
            $table->dropColumn(['playbook_markdown', 'playbook_updated_by_name', 'playbook_updated_at']);
        });
    }

    /** The starting playbook — plain, editable, without a single figure. */
    private const PLAYBOOK = <<<'MD'
# Pixel Alpha — video playbook

## Purpose
This is how we make short marketing videos for TikTok and X. The brand brief says who we are and how we sound; this playbook says how a video is built. A STYLE (chosen per video) decides the look. When the brief, a style and this playbook disagree, the brief wins, then the style, then this playbook.

## Anatomy of a video
- **Hook, 0–2 seconds.** The first frame must make someone stop scrolling: a bold line on screen, a surprising image, a question.
- **3 to 6 scenes**, each one idea. Most scenes are 4 to 10 seconds.
- **Numbers go on template cards** (a stat card, a scoreboard, a title card), never inside a generated clip, so they are exact and readable.
- **End with the disclaimer card** whenever the video cites a percentage.
- **30 seconds at most** in total. Shorter is usually better.

## Pixel is a guest, not the host
Pixel, our pixel-art robot mascot, is OFF by default. Use it only where the style or the plan asks for it:
- **none** — no Pixel at all: b-roll, charts, text cards.
- **cameo** — Pixel in ONE scene, usually the hook or the last scene.
- **lead** — Pixel carries the story, only when the style says so.
Never describe Pixel's look in a prompt: the character's look is added automatically for Pixel scenes.

## Writing scene prompts
- Say the subject, the action, the camera move and the light: "Slow push-in on a glowing trading screen at night, soft blue light, shallow focus".
- Say the shape of the video: vertical for 9:16, wide for 16:9, square for 1:1.
- No other brands' logos, no real people, no readable numbers inside a clip.
- One shot per scene. Keep it simple: the models do best with one clear action.

## Engines: Kling or Seedance
- **Kling** makes SILENT clips and is the cheaper choice. Use it for b-roll and shots the music carries.
- **Seedance** makes clips WITH SOUND. Use it when the scene needs its own sound: dialogue, a sound effect, music made for that shot. Say the sound in the prompt ("8-bit chiptune music and arcade sound effects", "a soft keyboard click").
- Start from the style's default engine; change it scene by scene when the sound calls for it.
- Template scenes (title, stat, scoreboard, level up, disclaimer) are drawn in the editor and cost nothing.

## Captions
- At most 6 words per caption. One idea per caption.
- The hook caption sits at the top; the rest at the bottom unless the style says otherwise.
- Figures in captions are copied exactly from the published results — never rounded, never invented.

## Sound
- Kling clips are silent: the music track, the chiptune generator and sound effects carry them.
- Seedance clips bring their own sound; lower the music under them.
- When there is a voiceover, duck the music under the voice.

## Recipes
### Weekly recap
1. Hook card with the week's headline.
2. Two or three b-roll scenes of markets, screens or a calm desk.
3. A stat card with ONE exact figure from the published results.
4. The disclaimer card.

### Coin spotlight
1. Hook: the coin's name on a title card.
2. A b-roll scene that sets the mood.
3. A stat card with that coin's published share of the week.
4. The disclaimer card.

### How it works
1. Connect an exchange account with a key that can only trade.
2. The bot trades while the person gets on with their day.
3. Pixel Alpha only earns a share when there is profit.
A clean title card per step works well; Pixel may cameo in the last scene.

### Pixel arcade recap
Use the Pixel arcade style: an 8-bit world, a HIGH SCORE scoreboard card for the week's figure, a LEVEL UP card, chiptune music and coin sounds, Pixel as a cameo.

## The asking routine
Before building a video, settle these in order, with buttons in the chat (unless the plan or the message already says):
1. Platform — X or TikTok.
2. Shape — 9:16, 16:9 or 1:1.
3. Style — from the style library.
Then show the scene list (label, seconds, engine, Pixel or not, caption) and ask "Build it?". Only after a yes, create the video.
MD;

    /** The five starting styles. Markdown ≤ 2,000 characters each. */
    private const STYLES = [
        [
            'slug' => 'data-recap',
            'name' => 'Data recap',
            'summary' => 'Clean charts and numbers: stat cards, calm confident b-roll, no mascot.',
            'markdown' => <<<'MD'
# Data recap

**Mood:** calm, confident, clear. The numbers are the star.

**Look:** clean dark backgrounds, soft blue and green light, charts and trading screens, slow camera moves. No mascot.

**Scenes**
- Open on a clean title card with the week's headline.
- Two or three silent b-roll scenes (Kling): glowing screens, a chart line rising, a quiet desk at night.
- One stat card per figure — one exact figure from the published results on each card.
- Close with the disclaimer card.

**Captions:** bold, short, one idea each. The figure itself lives on the card, the caption explains it.

**Sound:** no music by default; the team adds a track in the editor if they want one.
MD,
            'default_platform' => 'tiktok', 'default_aspect' => '9:16', 'pixel_default' => 'none', 'default_engine' => 'kling',
            'caption_style' => 'bold', 'template_family' => 'clean', 'music_default' => 'none', 'pixel_look' => false,
        ],
        [
            'slug' => 'pixel-arcade',
            'name' => 'Pixel arcade',
            'summary' => '8-bit arcade world: HIGH SCORE and LEVEL UP cards, chiptune and coin sounds, Pixel as a cameo.',
            'markdown' => <<<'MD'
# Pixel arcade

**Mood:** playful, retro, a game you want to win — never silly about money.

**Look:** everything is pixel art. An 8-bit world, arcade cabinet framing, chunky borders, scanlines. The pixel look filter is on.

**Scenes**
- Hook: a pixel title card ("PLAYER 1 READY" style headline).
- Pixel-art b-roll with Seedance: a neon arcade, coins spinning, a pixel city at night. Every Seedance prompt asks for "8-bit chiptune music and arcade sound effects" and says "pixel-art style".
- A HIGH SCORE scoreboard card for the week's published figure, copied exactly.
- A LEVEL UP card for a milestone or a feature.
- Pixel appears in ONE scene (a cameo), usually the hook or the end.
- Close with the disclaimer card.

**Captions:** the pixel caption style, short like game text.

**Sound:** the upbeat chiptune track, a coin sound on the scoreboard, a power-up sound on the level up card.
MD,
            'default_platform' => 'tiktok', 'default_aspect' => '9:16', 'pixel_default' => 'cameo', 'default_engine' => 'seedance',
            'caption_style' => 'pixel', 'template_family' => 'pixel', 'music_default' => 'arcade-upbeat', 'pixel_look' => true,
        ],
        [
            'slug' => 'pixel-storytime',
            'name' => 'Pixel storytime',
            'summary' => 'Pixel tells a tiny story in three or four scenes, with sound.',
            'markdown' => <<<'MD'
# Pixel storytime

**Mood:** warm, curious, a little funny. Pixel explains, it never brags.

**Look:** Pixel leads. Three or four scenes that follow one small story: a problem, Pixel helping, a happy ending.

**Scenes**
- Hook: Pixel in a surprising moment (waking up at a trading desk, waving from a rocket).
- Middle: Pixel shows one idea (trade-only keys, the money stays in your account, we only earn when you profit).
- End: Pixel waves goodbye next to the site name.
- Seedance for Pixel scenes, with the sound the moment needs (a soft beep, a cheerful jingle).
- Numbers, if any, go on a pixel stat card, followed by the disclaimer card.

**Captions:** bold, one line per scene, like a picture book.

**Sound:** the chill chiptune track under it all.
MD,
            'default_platform' => 'tiktok', 'default_aspect' => '9:16', 'pixel_default' => 'lead', 'default_engine' => 'seedance',
            'caption_style' => 'bold', 'template_family' => 'pixel', 'music_default' => 'arcade-chill', 'pixel_look' => false,
        ],
        [
            'slug' => 'cinematic-broll',
            'name' => 'Cinematic b-roll',
            'summary' => 'Moody city and market b-roll, slow camera moves, one strong line per scene; wide for X.',
            'markdown' => <<<'MD'
# Cinematic b-roll

**Mood:** serious, cinematic, premium.

**Look:** moody cities at night, rain on glass, trading floors, screens reflected in a window. Slow dolly and push-in moves, shallow focus, wide frames. Pixel only as a short cameo at the end.

**Scenes**
- Three or four Seedance scenes with ambient sound (city hum, rain, a soft synth).
- One strong line per scene, on screen as a clean caption.
- A clean stat card if a figure is cited, then the disclaimer card.

**Captions:** the clean style, few words, lots of space.

**Sound:** the clips' own ambient sound; no music track by default.
MD,
            'default_platform' => 'x', 'default_aspect' => '16:9', 'pixel_default' => 'cameo', 'default_engine' => 'seedance',
            'caption_style' => 'clean', 'template_family' => 'clean', 'music_default' => 'none', 'pixel_look' => false,
        ],
        [
            'slug' => 'fast-cut',
            'name' => 'Fast cut',
            'summary' => 'Five-second scenes, punchy captions, music-driven, no mascot.',
            'markdown' => <<<'MD'
# Fast cut

**Mood:** energetic, punchy, made for the scroll.

**Look:** quick five-second scenes, bold motion, high contrast. No mascot.

**Scenes**
- Five or six silent Kling scenes of 5 seconds each; the music carries them.
- Every scene changes something big: a new place, a new colour, a new move.
- Title and stat cards between clips for anything with a number, then the disclaimer card.

**Captions:** bold, three or four words each, a new one every scene.

**Sound:** the boss chiptune track, loud and driving; a blip sound on each cut if it helps.
MD,
            'default_platform' => 'tiktok', 'default_aspect' => '9:16', 'pixel_default' => 'none', 'default_engine' => 'kling',
            'caption_style' => 'bold', 'template_family' => 'clean', 'music_default' => 'arcade-boss', 'pixel_look' => false,
        ],
    ];
};
