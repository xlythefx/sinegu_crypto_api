<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The avatar every creative is made with ("Pixel"). Its text is added to the
 * prompt when a generation uses the character; its reference images are the
 * identity the reference-taking models are given.
 *
 * @property int $id
 * @property string $name
 * @property string|null $look
 * @property string|null $style_prompt
 * @property string|null $rules
 * @property string|null $updated_by_name
 */
class CreativeCharacter extends Model
{
    protected $table = 'creative_characters';

    protected $fillable = ['name', 'look', 'style_prompt', 'rules', 'updated_by_name'];

    public function refs(): HasMany
    {
        return $this->hasMany(CreativeCharacterRef::class, 'character_id')->orderBy('id');
    }

    /** The one character (the first row), seeded by the migration. */
    public static function current(): self
    {
        return self::orderBy('id')->first() ?? self::create(['name' => 'Pixel']);
    }
}
