<?php

namespace App\Http\Requests;

use App\Models\CreativeVideo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/admin/creatives/videos `{title, platform, aspect, style_id?,
 * plan_id?}` and PUT …/videos/{id} `{title?, style_id?, platform?, aspect?,
 * timeline?, scenes?}` (partial: an absent key is unchanged).
 *
 * The SHAPE is checked here. What depends on the video — the shape lock,
 * the 30-second cap, a scene belonging to the video, trims within the scene,
 * the TimelineDoc's version and size — is checked by
 * App\Services\Creatives\CreativeVideos, which also ignores every scene key
 * the server owns (generation_id, status…).
 */
class CreativeVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Behind the `staff` middleware.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $partial = $this->isMethod('put') || $this->isMethod('patch');
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        $rules = [
            'title' => [...$required, 'string', 'max:160'],
            'platform' => [...$required, 'string', Rule::in(CreativeVideo::PLATFORMS)],
            'aspect' => [...$required, 'string', Rule::in(CreativeVideo::ASPECTS)],
            'style_id' => ['sometimes', 'nullable', 'integer', 'exists:creative_styles,id'],
        ];
        if (! $partial) {
            // The plan is set when the video is made; a PUT ignores it.
            $rules['plan_id'] = ['sometimes', 'nullable', 'integer', 'exists:creative_plans,id'];
        } else {
            $rules += [
                'timeline' => ['sometimes', 'array'],
                'scenes' => ['sometimes', 'array', 'max:60'],
                'scenes.*' => ['array'],
                'scenes.*.id' => ['required', 'integer'],
                'scenes.*.position' => ['sometimes', 'integer', 'min:0', 'max:1000'],
                'scenes.*.label' => ['sometimes', 'string', 'max:80'],
                'scenes.*.trim_in_ms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:255000'],
                'scenes.*.trim_out_ms' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:255000'],
                'scenes.*.template' => ['sometimes', 'array'],
            ];
        }

        return $rules;
    }

    /**
     * The validated body, with `timeline` and each scene's `template` taken
     * from the raw input: validated() keeps only keys that have a rule, and
     * a TimelineDoc is the client's own nested document.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        $data = $this->validated();
        if (array_key_exists('timeline', $data)) {
            $data['timeline'] = $this->input('timeline');
        }
        if (isset($data['scenes'])) {
            $raw = (array) $this->input('scenes', []);
            foreach ($data['scenes'] as $i => $scene) {
                if (array_key_exists('template', $scene) || (is_array($raw[$i] ?? null) && array_key_exists('template', $raw[$i]))) {
                    $data['scenes'][$i]['template'] = $raw[$i]['template'] ?? null;
                }
            }
        }

        return $data;
    }
}
