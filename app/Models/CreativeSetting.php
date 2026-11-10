<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single settings row of Admin → Creatives (id 1): the monthly spending
 * cap. Generation locks this row while it checks the month's spend
 * (App\Services\Creatives\CreativeBudget::reserve).
 *
 * @property int $id
 * @property string $monthly_cap_usd
 * @property string|null $updated_by_uni_id
 * @property string|null $updated_by_name
 */
class CreativeSetting extends Model
{
    public const ID = 1;

    public const DEFAULT_CAP_USD = 100.00;

    protected $table = 'creative_settings';

    protected $fillable = ['monthly_cap_usd', 'updated_by_uni_id', 'updated_by_name'];

    protected function casts(): array
    {
        return [
            'monthly_cap_usd' => 'decimal:2',
        ];
    }

    /** The row, created with the default cap if a box somehow lost it. */
    public static function current(): self
    {
        return self::find(self::ID) ?? self::forceCreate([
            'id' => self::ID,
            'monthly_cap_usd' => self::DEFAULT_CAP_USD,
        ]);
    }
}
