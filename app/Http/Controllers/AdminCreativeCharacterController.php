<?php

namespace App\Http\Controllers;

use App\Models\CreativeCharacter;
use App\Models\CreativeCharacterRef;
use App\Models\CreativeGeneration;
use App\Services\Creatives\CreativePresenter;
use App\Services\Creatives\CreativeReferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Creatives → the avatar: its text (look, style, rules — added to
 * prompts) and its reference images, the only files the feature stores.
 */
class AdminCreativeCharacterController extends Controller
{
    public function __construct(
        private CreativePresenter $presenter,
        private CreativeReferences $references,
    ) {}

    /** GET /api/admin/creatives/character */
    public function show(): JsonResponse
    {
        return $this->answer(CreativeCharacter::current());
    }

    /** PUT /api/admin/creatives/character {name, look?, style_prompt?, rules?} — absent = unchanged, null = cleared. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'look' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'style_prompt' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'rules' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $character = CreativeCharacter::current();
        $character->fill(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data));
        $character->updated_by_name = $request->user()?->name;
        $character->save();

        return $this->answer($character);
    }

    /** POST /api/admin/creatives/character/refs (multipart `image`, `label?`) */
    public function storeRef(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'label' => ['nullable', 'string', 'max:80'],
        ]);

        $character = CreativeCharacter::current();
        $this->references->storeUpload($character, $request->file('image'), $data['label'] ?? null, $request->user()?->name);

        return $this->answer($character, 201);
    }

    /** POST /api/admin/creatives/character/refs/from-generation {generation_id, index, label?} */
    public function storeRefFromGeneration(Request $request): JsonResponse
    {
        $data = $request->validate([
            'generation_id' => ['required', 'integer', 'min:1'],
            'index' => ['required', 'integer', 'min:0', 'max:50'],
            'label' => ['nullable', 'string', 'max:80'],
        ]);

        $character = CreativeCharacter::current();
        $this->references->copyFromGeneration(
            $character,
            CreativeGeneration::find($data['generation_id']),
            (int) $data['index'],
            $data['label'] ?? null,
            $request->user()?->name,
        );

        return $this->answer($character, 201);
    }

    /** DELETE /api/admin/creatives/character/refs/{id} — the file goes too. */
    public function destroyRef(int $id): JsonResponse
    {
        $character = CreativeCharacter::current();
        $ref = CreativeCharacterRef::where('character_id', $character->id)->find($id);
        if (! $ref) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Reference image not found.'], 404);
        }
        $this->references->delete($ref);

        return $this->answer($character);
    }

    private function answer(CreativeCharacter $character, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'character' => $this->presenter->character($character->fresh())], $status);
    }
}
