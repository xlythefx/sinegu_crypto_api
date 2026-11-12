<?php

namespace App\Services\Creatives;

use App\Models\CreativeStyle;
use App\Models\CreativeVideo;
use App\Models\CreativeVideoScene;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The library of marketing STYLES (Admin → Creatives → Styles): the team's
 * Markdown style sheets the chat agent picks from, plus the defaults a new
 * video takes from its style (platform, shape, how much Pixel, the engine,
 * the caption style, the template family, the music, the pixel look).
 *
 * Every staff role edits them (collaborators are the marketing team). A
 * style a video still uses cannot be deleted (STYLE_IN_USE): switch it off
 * instead, or move the video to another style first.
 */
final class CreativeStyles
{
    /** Styles listed in the agent's [Context]. */
    public const CONTEXT_LIMIT = 12;

    public const CAPTION_STYLES = ['clean', 'bold', 'pixel'];

    public const MUSIC_PRESETS = ['arcade-upbeat', 'arcade-chill', 'arcade-boss', 'arcade-victory'];

    /** @return Collection<int, CreativeStyle> sort, then name; disabled included */
    public function all(): Collection
    {
        return CreativeStyle::query()->orderBy('sort')->orderBy('name')->orderBy('id')->get();
    }

    public function find(int $id): CreativeStyle
    {
        return CreativeStyle::find($id) ?? throw CreativeException::notFound('Style not found.');
    }

    public function bySlug(string $slug): ?CreativeStyle
    {
        return CreativeStyle::query()->where('slug', $slug)->first();
    }

    /**
     * The rules of POST (every field but id) and PUT (partial: an absent key
     * is unchanged).
     *
     * @return array<string, mixed>
     */
    public static function rules(bool $partial, ?int $ignoreId = null): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            'slug' => [...$required, 'string', 'regex:/^[a-z0-9-]{3,40}$/', Rule::unique('creative_styles', 'slug')->ignore($ignoreId)],
            'name' => [...$required, 'string', 'max:80'],
            'summary' => [...$required, 'string', 'max:200'],
            'markdown' => [...$required, 'string', 'max:'.CreativeStyle::MAX_MARKDOWN],
            'default_platform' => [...$required, 'string', Rule::in(CreativeStyle::PLATFORMS)],
            'default_aspect' => [...$required, 'string', Rule::in(CreativeVideo::ASPECTS)],
            'pixel_default' => [...$required, 'string', Rule::in(CreativeStyle::PIXEL)],
            'default_engine' => [...$required, 'string', Rule::in(CreativeVideoScene::ENGINES)],
            'caption_style' => [...$required, 'string', Rule::in(self::CAPTION_STYLES)],
            'template_family' => [...$required, 'string', Rule::in(CreativeStyle::TEMPLATE_FAMILIES)],
            'music_default' => [...$required, 'string', Rule::in(['none', ...self::MUSIC_PRESETS])],
            'pixel_look' => [...$required, 'boolean'],
            'enabled' => [...$required, 'boolean'],
            'sort' => [...$required, 'integer', 'min:-32768', 'max:32767'],
        ];
    }

    /** @param  array<string, mixed>  $data  already validated */
    public function create(array $data, ?string $byName): CreativeStyle
    {
        $style = new CreativeStyle;
        $style->fill(self::trimmed($data));
        $style->created_by_name = $byName;
        $style->updated_by_name = $byName;
        $style->save();

        return $style;
    }

    /** @param  array<string, mixed>  $data  already validated (partial) */
    public function update(CreativeStyle $style, array $data, ?string $byName): CreativeStyle
    {
        $style->fill(self::trimmed($data));
        $style->updated_by_name = $byName;
        $style->save();

        return $style;
    }

    public function delete(CreativeStyle $style): void
    {
        if (CreativeVideo::query()->where('style_id', $style->id)->exists()) {
            throw new CreativeException(
                'STYLE_IN_USE',
                'A video project still uses this style. Switch the style off instead, or give those videos another style first.',
                409,
            );
        }
        $style->delete();
    }

    /** The CreativeStyle shape of the API contract. */
    public function present(CreativeStyle $style): array
    {
        return [
            'id' => (int) $style->id,
            'slug' => $style->slug,
            'name' => $style->name,
            'summary' => $style->summary,
            'markdown' => (string) $style->markdown,
            'default_platform' => $style->default_platform,
            'default_aspect' => $style->default_aspect,
            'pixel_default' => $style->pixel_default,
            'default_engine' => $style->default_engine,
            'caption_style' => $style->caption_style,
            'template_family' => $style->template_family,
            'music_default' => $style->music_default,
            'pixel_look' => (bool) $style->pixel_look,
            'enabled' => (bool) $style->enabled,
            'sort' => (int) $style->sort,
            'updated_by_name' => $style->updated_by_name,
            'updated_at' => $style->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The enabled styles as the agent's [Context] lists them (≤ 12). Team-typed
     * text is scrubbed on its way to the model.
     *
     * @return list<array<string, string>>
     */
    public function forContext(): array
    {
        return CreativeStyle::query()->where('enabled', true)->orderBy('sort')->orderBy('name')->limit(self::CONTEXT_LIMIT)->get()
            ->map(fn (CreativeStyle $s) => [
                'slug' => $s->slug,
                'name' => CreativeAiPrompt::scrub((string) $s->name),
                'summary' => CreativeAiPrompt::scrub((string) $s->summary),
                'default_platform' => $s->default_platform,
                'default_aspect' => $s->default_aspect,
                'pixel_default' => $s->pixel_default,
                'default_engine' => $s->default_engine,
            ])
            ->values()->all();
    }

    /** Validate a body against {@see rules()}; the validated data. */
    public static function validate(array $body, bool $partial, ?int $ignoreId = null): array
    {
        return Validator::make($body, self::rules($partial, $ignoreId))->validate();
    }

    /** @param  array<string, mixed>  $data */
    private static function trimmed(array $data): array
    {
        return array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data);
    }
}
