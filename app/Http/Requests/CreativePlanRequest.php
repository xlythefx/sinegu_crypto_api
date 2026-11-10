<?php

namespace App\Http\Requests;

use App\Models\CreativePlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/admin/creatives/plans (create) and PUT …/plans/{id} (partial:
 * an ABSENT key is unchanged, null clears an optional field).
 */
class CreativePlanRequest extends FormRequest
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
        // Optional on create (defaults: idea / tiktok / video), never null.
        $choice = $partial ? ['sometimes', 'required'] : ['nullable'];

        return [
            'title' => [...$required, 'string', 'max:160'],
            'status' => [...$choice, 'string', Rule::in(CreativePlan::STATUSES)],
            'platform' => [...$choice, 'string', Rule::in(CreativePlan::PLATFORMS)],
            'format' => [...$choice, 'string', Rule::in(CreativePlan::FORMATS)],
            'planned_for' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hook' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'script' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'hashtags' => ['sometimes', 'nullable', 'string', 'max:500'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'posted_url' => ['sometimes', 'nullable', 'string', 'max:500', 'url:http,https'],
        ];
    }
}
