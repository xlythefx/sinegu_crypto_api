<?php

namespace Tests\Feature;

use App\Models\CreativeSetting;
use App\Models\CreativeStyle;
use App\Services\Creatives\CreativeAgentPrompt;
use App\Services\Creatives\CreativeAiPrompt;

/**
 * Owner, 2026-11-12: marketing videos end on a friendly note, never a risk warning. The playbook, the styles and both
 * prompts must not steer the AI to a disclaimer card or scare words; the caption keeps its automatic disclaimer line.
 */
class CreativesFriendlyEndingTest extends EngineTestCase
{
    public function test_the_playbook_and_styles_end_with_a_friendly_sign_off(): void
    {
        $playbook = (string) CreativeSetting::current()->playbook_markdown;
        $this->assertStringNotContainsString('End with the disclaimer card', $playbook);
        $this->assertStringNotContainsString('4. The disclaimer card.', $playbook);
        $this->assertStringContainsString('End on a friendly note, never a warning.', $playbook);

        foreach (CreativeStyle::query()->get() as $style) {
            $this->assertStringNotContainsString('disclaimer card', $style->markdown, "style {$style->slug}");
        }
        $this->assertStringContainsString('friendly sign-off card', CreativeStyle::query()->where('slug', 'data-recap')->value('markdown'));
    }

    public function test_the_prompts_ask_for_a_warm_ending_that_fits_the_story(): void
    {
        foreach ([CreativeAgentPrompt::SYSTEM, CreativeAiPrompt::SYSTEM] as $prompt) {
            $this->assertStringNotContainsString('Crypto trading is risky', $prompt);
            $this->assertStringNotContainsString('End with the disclaimer card', $prompt);
            $this->assertStringContainsString('Past performance does not guarantee future results.', $prompt); // the caption line stays
        }
        $this->assertStringContainsString('end every video on a warm, friendly note', CreativeAgentPrompt::SYSTEM);
        $this->assertStringContainsString('read the whole video in editing_video', CreativeAgentPrompt::SYSTEM);
    }
}
