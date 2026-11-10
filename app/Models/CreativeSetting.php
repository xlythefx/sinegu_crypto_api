<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single settings row of Admin → Creatives (id 1): the monthly spending
 * cap, and whether the AI writer drafts a weekly recap on Saturdays.
 * Generation and every AI call lock this row while they check the month's
 * spend (App\Services\Creatives\CreativeBudget).
 *
 * `updated_by_*` / `updated_at` describe the CAP (the Budget shape prints
 * them as "set by"); switching the weekly recap does not touch them.
 *
 * @property int $id
 * @property string $monthly_cap_usd
 * @property bool $ai_weekly_recap
 * @property string|null $updated_by_uni_id
 * @property string|null $updated_by_name
 */
class CreativeSetting extends Model
{
    public const ID = 1;

    public const DEFAULT_CAP_USD = 100.00;

    protected $table = 'creative_settings';

    protected $fillable = ['monthly_cap_usd', 'ai_weekly_recap', 'updated_by_uni_id', 'updated_by_name'];

    protected function casts(): array
    {
        return [
            'monthly_cap_usd' => 'decimal:2',
            'ai_weekly_recap' => 'boolean',
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
