<?php

namespace App\Http\Requests;

use App\Services\Creatives\CreativeStyles;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/admin/creatives/styles (every field but id) and PUT …/styles/{id}
 * (partial: an absent key is unchanged). The rules live in
 * {@see CreativeStyles::rules()}.
 */
class CreativeStyleRequest extends FormRequest
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
        $id = $this->route('id');

        return CreativeStyles::rules($partial, $id !== null ? (int) $id : null);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => 'A slug is 3 to 40 lowercase letters, digits or dashes.',
            'slug.unique' => 'Another style already has this slug.',
        ];
    }
}
