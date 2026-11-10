<?php

namespace App\Http\Requests;

use App\Models\CreativePlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/admin/creatives/ai/draft —
 * `{brief (3–500), platform: x|tiktok, format?: video|image|carousel (video),
 * plan_id?, use_facts?: bool (true)}`. The draft is NOT saved; `plan_id` only
 * links the call's cost record to the plan being edited.
 */
class CreativeAiDraftRequest extends FormRequest
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
            'brief' => ['required', 'string', 'min:3', 'max:500'],
            'platform' => ['required', 'string', Rule::in(CreativePlan::PLATFORMS)],
            'format' => ['nullable', 'string', Rule::in(CreativePlan::FORMATS)],
            'plan_id' => ['nullable', 'integer', 'exists:creative_plans,id'],
            'use_facts' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'brief.min' => 'Describe the post in at least a few words.',
        ];
    }
}
