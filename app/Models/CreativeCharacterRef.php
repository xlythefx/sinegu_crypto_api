<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reference image of the character, on the PRIVATE local disk
 * (creatives/refs/<random>.<ext>). Served only through a signed URL
 * (CreativeAssetController::refImage), never from a public folder.
 *
 * @property int $id
 * @property int $character_id
 * @property string|null $label
 * @property string $path
 * @property string $mime
 * @property int $bytes
 * @property string $source upload | generation
 * @property int|null $generation_id
 * @property string|null $created_by_name
 */
class CreativeCharacterRef extends Model
{
    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_GENERATION = 'generation';

    protected $table = 'creative_character_refs';

    protected $fillable = ['character_id', 'label', 'path', 'mime', 'bytes', 'source', 'generation_id', 'created_by_name'];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'generation_id' => 'integer',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(CreativeCharacter::class, 'character_id');
    }
}
