<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One file of the Creatives audio library — music the team holds the rights
 * to, or a recorded voiceover — on the PRIVATE local disk
 * (creatives/audio/<random>.<ext>), served only through a signed link
 * (CreativeAssetController::audioFile). Never a video.
 *
 * @property int $id
 * @property string $kind music | voiceover
 * @property string $label
 * @property string $mime
 * @property int $bytes
 * @property int|null $duration_ms
 * @property string $path
 * @property string $created_by_name
 */
class CreativeAudioAsset extends Model
{
    public const KINDS = ['music', 'voiceover'];

    protected $table = 'creative_audio_assets';

    protected $fillable = ['kind', 'label', 'mime', 'bytes', 'duration_ms', 'path', 'created_by_name'];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'duration_ms' => 'integer',
        ];
    }
}
