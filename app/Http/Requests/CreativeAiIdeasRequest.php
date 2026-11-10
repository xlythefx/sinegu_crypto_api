<?php

namespace App\Http\Requests;

use App\Models\CreativePlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/admin/creatives/ai/ideas —
 * `{count?: 3–8 (5), focus?: string ≤ 300, platform?: x|tiktok|null}`.
 */
class CreativeAiIdeasRequest extends FormRequest
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
            'count' => ['nullable', 'integer', 'between:3,8'],
            'focus' => ['nullable', 'string', 'max:300'],
            'platform' => ['nullable', 'string', Rule::in(CreativePlan::PLATFORMS)],
        ];
    }
}
