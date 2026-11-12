<?php

namespace App\Http\Requests;

use App\Models\CreativeAgentSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/admin/creatives/agent/turn — `{session_id, mode, text?,
 * focus_scene_ids?}`. `focus_scene_ids` (≤ 12, the editor chat's selected
 * scenes) must belong to the chat's video — checked by CreativeAgent
 * (422 on `focus_scene_ids`).
 *
 * `text` is optional HERE because a continue step sends none; whether this
 * step needs it (the last stored message is an answer with nothing pending)
 * is decided by CreativeAgent, which answers the same 422 on `text`.
 */
class CreativeAgentTurnRequest extends FormRequest
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
            'session_id' => ['required', 'string', 'uuid'],
            'mode' => ['required', 'string', Rule::in(CreativeAgentSession::MODES)],
            'text' => ['nullable', 'string', 'min:1', 'max:4000'],
            'focus_scene_ids' => ['nullable', 'array', 'max:12'],
            'focus_scene_ids.*' => ['integer', 'min:1'],
        ];
    }
}
