<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Claude call made by Admin → Creatives' AI writer (a draft, a list of
 * ideas, or the Saturday weekly recap): the tokens and what they cost.
 *
 * Counts against the SAME monthly cap as Higgsfield generations
 * (App\Services\Creatives\CreativeBudget). A row whose `stop_reason` is NULL
 * is a call still in flight — its `cost_usd` is the reservation
 * (CreativeAi::MAX_CALL_USD) until Claude's answer replaces it with the real
 * figure. Never deleted once Claude answered: refusals cost tokens too.
 *
 * @property int $id
 * @property string $kind draft | ideas | weekly
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string $cost_usd
 * @property string|null $stop_reason
 * @property int|null $plan_id
 * @property string|null $brief
 * @property string|null $created_by_uni_id
 * @property string|null $created_by_name
 */
class CreativeAiCall extends Model
{
    public const DRAFT = 'draft';

    public const IDEAS = 'ideas';

    public const WEEKLY = 'weekly';

    /** The answer never arrived although the request may have run: the reservation is kept. */
    public const UNCONFIRMED = 'unconfirmed';

    protected $table = 'creative_ai_calls';

    protected $fillable = [
        'kind', 'model', 'input_tokens', 'output_tokens', 'cost_usd', 'stop_reason', 'plan_id',
        'brief', 'created_by_uni_id', 'created_by_name',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_usd' => 'decimal:4',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CreativePlan::class, 'plan_id');
    }
}
