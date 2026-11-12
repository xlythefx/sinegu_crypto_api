<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner, 2026-11-12: marketing videos end on a friendly note, never a risk warning. The seeded playbook and styles
 * told the agent to "end with the disclaimer card"; this rewrites exactly those lines (a line the team has edited is
 * left alone). The caption keeps its automatic "Past performance does not guarantee future results." line.
 */
return new class extends Migration
{
    /** @var array<string, string> old line => new line, in the playbook */
    private const PLAYBOOK = [
        '- **End with the disclaimer card** whenever the video cites a percentage.' => '- **End on a friendly note, never a warning.** Close with a warm goodbye that fits the story: Pixel waving, a "see you next week" card, the site name. No risk warnings or disclaimer cards on screen: this is marketing, and the post\'s caption carries the disclaimer line (added automatically when a figure is cited).',
        '- Template scenes (title, stat, scoreboard, level up, disclaimer) are drawn in the editor and cost nothing.' => '- Template scenes (title, stat, scoreboard, level up) are drawn in the editor and cost nothing. A disclaimer card exists too, but only when someone asks for it.',
        "3. A stat card with ONE exact figure from the published results.\n4. The disclaimer card." => "3. A stat card with ONE exact figure from the published results.\n4. A friendly sign-off card: \"See you next week\" and the site name.",
        "3. A stat card with that coin's published share of the week.\n4. The disclaimer card." => "3. A stat card with that coin's published share of the week.\n4. A friendly sign-off: a short goodbye and the site name.",
    ];

    /** @var array<string, string> old line => new line, in the styles */
    private const STYLES = [
        '- Close with the disclaimer card.' => '- Close with a friendly sign-off card: a short goodbye and the site name.',
        '- Numbers, if any, go on a pixel stat card, followed by the disclaimer card.' => '- Numbers, if any, go on a pixel stat card.',
        '- A clean stat card if a figure is cited, then the disclaimer card.' => '- A clean stat card if a figure is cited.',
        '- Title and stat cards between clips for anything with a number, then the disclaimer card.' => '- Title and stat cards between clips for anything with a number; end on a short, friendly sign-off.',
    ];

    public function up(): void
    {
        $this->rewrite(self::PLAYBOOK, self::STYLES);
    }

    public function down(): void
    {
        $this->rewrite(array_flip(self::PLAYBOOK), array_flip(self::STYLES));
    }

    /**
     * @param  array<string, string>  $playbook
     * @param  array<string, string>  $styles
     */
    private function rewrite(array $playbook, array $styles): void
    {
        foreach (DB::table('creative_settings')->whereNotNull('playbook_markdown')->get(['id', 'playbook_markdown']) as $row) {
            $new = strtr(str_replace("\r\n", "\n", $row->playbook_markdown), $playbook);
            if ($new !== str_replace("\r\n", "\n", $row->playbook_markdown)) {
                // No playbook_updated_at: that field means "a person edited it". Chats see the change anyway (versioned by content).
                DB::table('creative_settings')->where('id', $row->id)->update(['playbook_markdown' => $new]);
            }
        }

        foreach (DB::table('creative_styles')->get(['id', 'markdown']) as $row) {
            $new = strtr(str_replace("\r\n", "\n", $row->markdown), $styles);
            if ($new !== str_replace("\r\n", "\n", $row->markdown)) {
                DB::table('creative_styles')->where('id', $row->id)->update(['markdown' => $new, 'updated_at' => now()]);
            }
        }
    }
};
