<?php

namespace App\Services\Creatives;

use Illuminate\Validation\ValidationException;

/**
 * The Higgsfield models the Creatives page offers — a fixed registry, so the
 * endpoint a request reaches is always ours, never client text. The frontend
 * renders whatever `GET /admin/creatives` returns from {@see all()}.
 *
 * Each param's `name` is also its field in the Higgsfield body, so building a
 * body is the prompt, the validated params and the image field(s).
 *
 * Prices (estimate endpoint, 2026-11-10) are only the picker's hint; the real
 * figure always comes from Higgsfield's free price check. The one exception
 * is Seedance, whose estimate answers a text description instead of a
 * number: its `usd_per_second` table is what we reserve against.
 */
final class CreativeModels
{
    public const SOUL = 'soul-image';

    public const GROK = 'grok-image';

    public const KLING = 'kling-animate';

    public const SEEDANCE = 'seedance-reference';

    /** How long Higgsfield keeps a result's files (its default; `expires_at` decides). */
    public const RETENTION_DAYS = 7;

    /**
     * Sent as the image(s) in a PRICE CHECK only: the estimate does not fetch
     * image URLs, so the references are uploaded only for a real submit.
     */
    public const PLACEHOLDER_IMAGE = 'https://pixel-alpha.com/creatives-estimate-placeholder.png';

    private const PRESETS = [
        self::SOUL => [
            'label' => 'Image from text (Soul)',
            'kind' => 'image',
            'endpoint' => 'higgsfield-ai/soul/v2/standard',
            'description' => 'A still picture from your words alone, no reference images. The cheapest way to try out looks for the avatar.',
            'images' => null,
            'price_hint' => 'under $0.01 at 720p, about $0.02 for 4 images at 1080p',
            'params' => [
                ['name' => 'aspect_ratio', 'label' => 'Shape', 'type' => 'select', 'options' => ['9:16', '16:9', '1:1', '4:3', '3:4', '2:3', '3:2'], 'default' => '9:16'],
                ['name' => 'resolution', 'label' => 'Resolution', 'type' => 'select', 'options' => ['720p', '1080p'], 'default' => '720p'],
                ['name' => 'batch_size', 'label' => 'Images', 'type' => 'select', 'options' => [1, 4], 'default' => 1],
            ],
        ],
        self::GROK => [
            'label' => 'Character image (with references)',
            'kind' => 'image',
            'endpoint' => 'xai/grok-imagine-image-2.0',
            'description' => 'A still of the avatar, drawn from its reference images so it looks the same every time.',
            'images' => 'image_urls',
            'max_images' => 10,
            'price_hint' => 'about $0.07 per image',
            'params' => [
                ['name' => 'aspect_ratio', 'label' => 'Shape', 'type' => 'select', 'options' => ['auto', '1:1', '1:2', '2:1', '3:2', '2:3', '4:3', '3:4', '16:9', '9:16'], 'default' => '9:16'],
                ['name' => 'resolution', 'label' => 'Resolution', 'type' => 'select', 'options' => ['1k', '2k'], 'default' => '1k'],
                ['name' => 'quality', 'label' => 'Quality', 'type' => 'select', 'options' => ['low', 'medium'], 'default' => 'medium'],
            ],
        ],
        self::KLING => [
            'label' => 'Animate an image (Kling 2.5 Turbo)',
            'kind' => 'video',
            'endpoint' => 'kling-video/v2.5-turbo/pro/image-to-video',
            'description' => 'Brings one still to life as a short video. The video keeps the image\'s shape, so make the still 9:16 for TikTok.',
            'images' => 'image_url',
            'price_hint' => 'about $0.26 for 5 s, $0.53 for 10 s',
            // Kling's price check stopped answering a number by 2026-11-11 and
            // now answers a description ("1080p: $0.0525 per video second"),
            // which left every animate step unpriced. One output resolution,
            // so one rate (seen live 2026-11-11).
            'usd_per_second' => 0.0525,
            'params' => [
                ['name' => 'duration', 'label' => 'Length (seconds)', 'type' => 'select', 'options' => [5, 10], 'default' => 5],
                ['name' => 'negative_prompt', 'label' => 'Avoid (optional)', 'type' => 'text', 'default' => null, 'max_length' => 500],
            ],
        ],
        self::SEEDANCE => [
            'label' => 'Character video (Seedance 2.5)',
            'kind' => 'video',
            'endpoint' => 'bytedance/seedance-2.5/reference-to-video',
            'description' => 'A video of the avatar made straight from its reference images, with sound if you want it.',
            'images' => 'image_urls',
            'max_images' => 10,
            // Reference-to-video needs at least one reference: Higgsfield
            // refuses the request (even its price check) with none, so this
            // model always uses the character. Seen live 2026-11-10.
            'requires_character' => true,
            'price_hint' => 'about $0.46 per second at 720p ($2.31 for 5 s), $0.21 per second at 480p',
            // Seedance's price check answers a description, not a number.
            'usd_per_second' => ['480p' => 0.2056, '720p' => 0.4622],
            'params' => [
                ['name' => 'duration', 'label' => 'Length (seconds)', 'type' => 'integer', 'min' => 4, 'max' => 15, 'default' => 5],
                ['name' => 'resolution', 'label' => 'Resolution', 'type' => 'select', 'options' => ['480p', '720p'], 'default' => '720p'],
                ['name' => 'aspect_ratio', 'label' => 'Shape', 'type' => 'select', 'options' => ['9:16', '16:9', '1:1', '3:4', '4:3'], 'default' => '9:16'],
                ['name' => 'generate_audio', 'label' => 'Sound', 'type' => 'boolean', 'default' => true],
            ],
        ],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::PRESETS);
    }

    public function has(string $key): bool
    {
        return isset(self::PRESETS[$key]);
    }

    /** @return array<string, mixed>|null the internal preset (endpoint, images field, pricing) */
    public function get(string $key): ?array
    {
        return isset(self::PRESETS[$key]) ? ['key' => $key] + self::PRESETS[$key] : null;
    }

    public function label(string $key): string
    {
        return self::PRESETS[$key]['label'] ?? $key;
    }

    /** Takes the character's reference images (grok-image, seedance-reference). */
    public function usesReferences(string $key): bool
    {
        return (self::PRESETS[$key]['images'] ?? null) === 'image_urls';
    }

    /** Cannot run without the character's references, so "Use the character" is always on. */
    public function requiresCharacter(string $key): bool
    {
        return (bool) (self::PRESETS[$key]['requires_character'] ?? false);
    }

    /** Animates one chosen image (kling-animate). */
    public function needsSource(string $key): bool
    {
        return (self::PRESETS[$key]['images'] ?? null) === 'image_url';
    }

    /** The picker's list — the ModelPreset shape of the API contract. */
    public function all(): array
    {
        $out = [];
        foreach (self::PRESETS as $key => $p) {
            $out[] = [
                'key' => $key,
                'label' => $p['label'],
                'kind' => $p['kind'],
                'description' => $p['description'],
                'uses_references' => $this->usesReferences($key),
                'requires_character' => $this->requiresCharacter($key),
                'needs_source' => $this->needsSource($key),
                'price_hint' => $p['price_hint'],
                'params' => array_map(fn (array $param) => array_filter([
                    'name' => $param['name'],
                    'label' => $param['label'],
                    'type' => $param['type'],
                    'options' => $param['options'] ?? null,
                    'min' => $param['min'] ?? null,
                    'max' => $param['max'] ?? null,
                    'default' => $param['default'],
                ], fn ($v, $k) => $k === 'default' || $v !== null, ARRAY_FILTER_USE_BOTH), $p['params']),
            ];
        }

        return $out;
    }

    /**
     * The params a request may send for this model, each checked against the
     * registry and filled with its default. Unknown names are dropped, so
     * nothing a client invents reaches Higgsfield's body.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validateParams(string $key, mixed $raw): array
    {
        $preset = self::PRESETS[$key];
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        $errors = [];

        foreach ($preset['params'] as $param) {
            $name = $param['name'];
            $given = array_key_exists($name, $raw) ? $raw[$name] : null;
            if ($given === null || $given === '') {
                $out[$name] = $param['default'];

                continue;
            }

            switch ($param['type']) {
                case 'select':
                    $match = null;
                    foreach ($param['options'] as $option) {
                        if (is_scalar($given) && (string) $option === (string) $given) {
                            $match = $option;
                            break;
                        }
                    }
                    if ($match === null) {
                        $errors["params.{$name}"] = "{$param['label']} must be one of: ".implode(', ', $param['options']).'.';
                    } else {
                        $out[$name] = $match;
                    }
                    break;

                case 'integer':
                    $int = filter_var($given, FILTER_VALIDATE_INT);
                    if ($int === false || $int < $param['min'] || $int > $param['max']) {
                        $errors["params.{$name}"] = "{$param['label']} must be a whole number from {$param['min']} to {$param['max']}.";
                    } else {
                        $out[$name] = $int;
                    }
                    break;

                case 'boolean':
                    $bool = is_bool($given) ? $given : filter_var($given, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($bool === null) {
                        $errors["params.{$name}"] = "{$param['label']} must be on or off.";
                    } else {
                        $out[$name] = $bool;
                    }
                    break;

                case 'text':
                    if (! is_string($given)) {
                        $errors["params.{$name}"] = "{$param['label']} must be text.";
                    } elseif (mb_strlen(trim($given)) > ($param['max_length'] ?? 500)) {
                        $errors["params.{$name}"] = "{$param['label']} may be at most ".($param['max_length'] ?? 500).' characters.';
                    } else {
                        $out[$name] = trim($given) === '' ? null : trim($given);
                    }
                    break;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * The Higgsfield request body: the prompt, the params that have a value,
     * and the image field the model takes.
     *
     * @param  array<string, mixed>  $params  from validateParams()
     * @param  list<string>  $imageUrls  references (image_urls models)
     */
    public function body(string $key, string $fullPrompt, array $params, array $imageUrls = [], ?string $imageUrl = null): array
    {
        $preset = self::PRESETS[$key];
        $body = ['prompt' => $fullPrompt];
        foreach ($params as $name => $value) {
            if ($value !== null) {
                $body[$name] = $value;
            }
        }

        if ($preset['images'] === 'image_urls' && $imageUrls !== []) {
            $body['image_urls'] = array_values(array_slice($imageUrls, 0, $preset['max_images'] ?? 10));
        } elseif ($preset['images'] === 'image_url' && $imageUrl !== null) {
            $body['image_url'] = $imageUrl;
        }

        return $body;
    }

    /**
     * Our own price for a model whose estimate answers a description instead
     * of a number (Seedance, Kling): the per-second rate × duration — a table
     * by resolution, or one rate for a model with a single output resolution.
     * Null for every other model — no number from Higgsfield, no generation.
     *
     * @param  array<string, mixed>  $params
     */
    public function fallbackUsd(string $key, array $params): ?float
    {
        $rates = self::PRESETS[$key]['usd_per_second'] ?? null;
        $rate = match (true) {
            is_array($rates) => $rates[(string) ($params['resolution'] ?? '')] ?? null,
            is_float($rates) => $rates,
            default => null,
        };
        $seconds = (int) ($params['duration'] ?? 0);
        if ($rate === null || $seconds <= 0) {
            return null;
        }

        return round($rate * $seconds, 4);
    }
}
