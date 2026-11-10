<?php

namespace App\Services\Creatives;

use App\Models\CreativeCharacter;

/**
 * What the model is actually sent. With the character on, its text follows
 * the person's prompt so every creative carries the same look, style and
 * rules:
 *
 *     {prompt}
 *
 *     Character "{name}": {look}
 *     Style: {style_prompt}
 *     Rules: {rules}
 *
 * A line whose value is empty is left out (and the blank line with it when
 * all three are). With the character off, the prompt alone.
 */
final class CreativePrompt
{
    public static function assemble(string $prompt, ?CreativeCharacter $character, bool $useCharacter): string
    {
        $prompt = trim($prompt);
        if (! $useCharacter || $character === null) {
            return $prompt;
        }

        $lines = [];
        $look = trim((string) $character->look);
        if ($look !== '') {
            $lines[] = 'Character "'.trim($character->name).'": '.$look;
        }
        $style = trim((string) $character->style_prompt);
        if ($style !== '') {
            $lines[] = 'Style: '.$style;
        }
        $rules = trim((string) $character->rules);
        if ($rules !== '') {
            $lines[] = 'Rules: '.$rules;
        }

        return $lines === [] ? $prompt : $prompt."\n\n".implode("\n", $lines);
    }
}
