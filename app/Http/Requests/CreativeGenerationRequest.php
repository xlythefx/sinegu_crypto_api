<?php

namespace App\Http\Requests;

use App\Services\Creatives\CreativeModels;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/admin/creatives/generations and …/generations/estimate (same
 * body): `{model, prompt, params, use_character?, plan_id?, source?}`.
 *
 * The SHAPE is checked here; each model's params are checked against the
 * preset registry (CreativeModels::validateParams) and the kling `source`
 * against what it names (CreativeGenerator) — both still answer Laravel's
 * normal 422 for a malformed value.
 */
class CreativeGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Behind the `staff` middleware.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'model' => ['required', 'string', Rule::in(CreativeModels::keys())],
            'prompt' => ['required', 'string', 'min:3', 'max:2000'],
            'params' => ['nullable', 'array'],
            'use_character' => ['nullable', 'boolean'],
            'plan_id' => ['nullable', 'integer', 'exists:creative_plans,id'],
            'source' => ['nullable', 'array'],
            'source.generation_id' => ['nullable', 'integer', 'min:1'],
            'source.index' => ['nullable', 'integer', 'min:0', 'max:50'],
            'source.ref_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'model.in' => 'Pick one of the models on the page.',
            'prompt.min' => 'Describe what you want in at least a few characters.',
        ];
    }
}
