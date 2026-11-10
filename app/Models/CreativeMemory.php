<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One team-wide note the Creatives agent always reads (Admin → Creatives →
 * Memory): a brand decision, what the audience responds to, how the team
 * works. Saved by a person, or by the agent when the person asks it to.
 * Never a figure that is not public, never anything about a person.
 *
 * @property int $id
 * @property string $text
 * @property string $category brand | audience | performance | process | other
 * @property string $source agent | person
 * @property string|null $created_by_name
 * @property string|null $updated_by_name
 */
class CreativeMemory extends Model
{
    public const CATEGORIES = ['brand', 'audience', 'performance', 'process', 'other'];

    public const SOURCE_AGENT = 'agent';

    public const SOURCE_PERSON = 'person';

    public const MAX_TEXT = 500;

    /** The most notes kept: they go into the chat's context. */
    public const MAX_ROWS = 200;

    protected $table = 'creative_memories';

    protected $fillable = ['text', 'category', 'source', 'created_by_name', 'updated_by_name'];
}
