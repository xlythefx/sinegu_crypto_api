<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/auth/email/verify — the six digits from the verification email. */
class VerifyEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is behind auth:sanctum; the code is checked against the
        // signed-in user's own row.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'digits:6'],
        ];
    }
}
