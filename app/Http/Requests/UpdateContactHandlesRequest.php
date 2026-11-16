<?php

namespace App\Http\Requests;

use App\Services\Signup\ContactHandles;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/user/contacts — Settings → Telegram, Discord, WhatsApp. Every key
 * optional: an ABSENT key leaves that value as it is. null or '' clears
 * Discord; Telegram and WhatsApp are required, so sending them empty is a
 * field error (ContactHandles::rules without $signup). Normalised first (ContactHandles), so `@name` and t.me links
 * pass as the bare username and a bad one is a plain field error.
 */
class UpdateContactHandlesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Behind auth:sanctum; the handles saved are always the caller's own.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ContactHandles::normalized($this->all()));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ContactHandles::rules();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ContactHandles::messages();
    }
}
