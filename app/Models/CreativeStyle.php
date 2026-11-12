<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One marketing STYLE of the Creatives library (data recap, pixel arcade,
 * storytime…): the look a video takes. The video playbook is the base every
 * video follows; a style is what makes two videos look different. The team
 * edits them on the page; the chat agent reads the enabled ones' summaries in
 * every [Context] and a chosen one's Markdown through `get_style`.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $summary
 * @property string $markdown
 * @property string $default_platform x | tiktok | any
 * @property string $default_aspect 9:16 | 16:9 | 1:1
 * @property string $pixel_default none | cameo | lead
 * @property string $default_engine kling | seedance
 * @property string $caption_style clean | bold | pixel
 * @property string $template_family clean | pixel
 * @property string $music_default none | a chiptune preset
 * @property bool $pixel_look
 * @property bool $enabled
 * @property int $sort
 * @property string|null $created_by_name
 * @property string|null $updated_by_name
 */
class CreativeStyle extends Model
{
    public const PLATFORMS = ['x', 'tiktok', 'any'];

    public const PIXEL = ['none', 'cameo', 'lead'];

    public const TEMPLATE_FAMILIES = ['clean', 'pixel'];

    public const MAX_MARKDOWN = 20000;

    protected $table = 'creative_styles';

    protected $fillable = [
        'slug', 'name', 'summary', 'markdown', 'default_platform', 'default_aspect', 'pixel_default', 'default_engine',
        'caption_style', 'template_family', 'music_default', 'pixel_look', 'enabled', 'sort',
        'created_by_name', 'updated_by_name',
    ];

    protected function casts(): array
    {
        return [
            'pixel_look' => 'boolean',
            'enabled' => 'boolean',
            'sort' => 'integer',
        ];
    }
}
