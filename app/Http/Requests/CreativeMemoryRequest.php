<?php

namespace App\Http\Requests;

use App\Models\CreativeMemory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/admin/creatives/memory `{text, category?}` and PUT …/memory/{id}
 * `{text?, category?}` (partial: an absent key is unchanged).
 */
class CreativeMemoryRequest extends FormRequest
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

        return [
            'text' => [...($partial ? ['sometimes', 'required'] : ['required']), 'string', 'max:'.CreativeMemory::MAX_TEXT],
            'category' => [...($partial ? ['sometimes', 'required'] : ['nullable']), 'string', Rule::in(CreativeMemory::CATEGORIES)],
        ];
    }
}
