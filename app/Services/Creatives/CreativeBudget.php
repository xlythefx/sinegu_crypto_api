<?php

namespace App\Services\Creatives;

use App\Models\CreativeAiCall;
use App\Models\CreativeGeneration;
use App\Models\CreativeSetting;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The monthly spending cap of Admin → Creatives, and the one place it is
 * enforced.
 *
 * The month is the UTC calendar month of a row's `created_at`; the month's
 * spend is SUM(generations.charged_usd) + SUM(ai_calls.cost_usd) — Higgsfield
 * generations AND the AI writer's Claude calls, one number to watch. A
 * generation reserves its estimate the moment it is created (charged_usd =
 * estimated_usd) and gives it back only when Higgsfield says it ended without
 * a result — so a pending job always counts against the cap. An AI call
 * reserves {@see CreativeAi::MAX_CALL_USD} the same way (its row exists, with
 * `stop_reason` NULL, while Claude is answering) and is settled to the real
 * cost from the response's usage.
 *
 * Money is compared in ten-thousandths of a dollar (integers), never as
 * floats: 99.70 + 0.30 must equal the 100.00 cap, not exceed it.
 */
final class CreativeBudget
{
    /**
     * Run $create only if $usd still fits under the cap, holding a row lock
     * on the settings row for the check and the insert — so two generations
     * started at the same instant cannot both see the old total. Null when
     * it does not fit (cap 0 = generation off: nothing ever fits).
     *
     * @param  Closure(): CreativeGeneration  $create
     */
    public function reserve(float $usd, Closure $create): ?CreativeGeneration
    {
        return $this->underCap($usd, $create);
    }

    /**
     * The AI writer's twin of {@see reserve()}: creates the call's row (at
     * the reservation, MAX_CALL_USD) only if that still fits, under the same
     * lock — so a draft, a generation and the weekly recap started at the
     * same instant cannot together slip past the cap. The lock is released
     * before Claude is called; the row then holds the place.
     *
     * @param  Closure(): CreativeAiCall  $create
     */
    public function reserveAiCall(float $usd, Closure $create): ?CreativeAiCall
    {
        return $this->underCap($usd, $create);
    }

    private function underCap(float $usd, Closure $create): mixed
    {
        CreativeSetting::current();

        return DB::transaction(function () use ($usd, $create) {
            // The lock is the FIRST read of the transaction: InnoDB takes its
            // snapshot at the first plain read, so the SUM below — after the
            // lock was granted — sees every row committed before it.
            $setting = CreativeSetting::query()->whereKey(CreativeSetting::ID)->lockForUpdate()->firstOrFail();
            if (! $this->fits((float) $setting->monthly_cap_usd, $this->spent(), $usd)) {
                return null;
            }

            return $create();
        });
    }

    /** The same arithmetic without the lock — for the price check's `allowed`. */
    public function allows(float $usd): bool
    {
        return $this->fits((float) CreativeSetting::current()->monthly_cap_usd, $this->spent(), $usd);
    }

    /**
     * The Budget shape of the API contract, for the current month. `ai_usd` /
     * `ai_calls` are the AI writer's part of `spent_usd`; `by_person` sums
     * both sources per person (`generations` stays the generation count).
     */
    public function summary(): array
    {
        $setting = CreativeSetting::current();
        $cap = (float) $setting->monthly_cap_usd;
        $spent = $this->spent();

        $people = [];
        $add = function (?string $uniId, ?string $name, float $usd, int $generations, int $aiCalls) use (&$people) {
            // No uni_id (the weekly recap): grouped by the name it carries.
            $key = $uniId ?? 'name:'.($name ?? '');
            $people[$key] ??= ['uni_id' => $uniId, 'name' => $name, 'spent' => 0.0, 'generations' => 0, 'ai_calls' => 0];
            $people[$key]['name'] ??= $name;
            $people[$key]['spent'] += $usd;
            $people[$key]['generations'] += $generations;
            $people[$key]['ai_calls'] += $aiCalls;
        };
        $this->thisMonth()->toBase()
            ->selectRaw('created_by_uni_id, MAX(created_by_name) AS name, SUM(charged_usd) AS spent, COUNT(*) AS n')
            ->groupBy('created_by_uni_id')
            ->get()
            ->each(fn ($row) => $add($row->created_by_uni_id, $row->name, (float) $row->spent, (int) $row->n, 0));
        $this->aiThisMonth()->toBase()
            ->selectRaw('created_by_uni_id, created_by_name AS name, SUM(cost_usd) AS spent, COUNT(*) AS n')
            ->groupBy('created_by_uni_id', 'created_by_name')
            ->get()
            ->each(fn ($row) => $add($row->created_by_uni_id, $row->name, (float) $row->spent, 0, (int) $row->n));

        $byPerson = collect($people)
            ->sortByDesc('spent')
            ->map(fn (array $p) => [
                'uni_id' => $p['uni_id'],
                'name' => $p['name'],
                'spent_usd' => self::money($p['spent']),
                'generations' => $p['generations'],
                'ai_calls' => $p['ai_calls'],
            ])
            ->values()
            ->all();

        // A pending generation, or an AI call Claude is still answering.
        $reserved = (float) $this->thisMonth()->whereIn('status', CreativeGeneration::PENDING)->sum('charged_usd')
            + (float) $this->aiThisMonth()->whereNull('stop_reason')->sum('cost_usd');

        return [
            'month' => $this->monthStart()->format('Y-m'),
            'cap_usd' => round($cap, 2),
            'spent_usd' => self::money($spent),
            'reserved_usd' => self::money($reserved),
            'remaining_usd' => self::money(max(0, self::units($cap) - self::units($spent)) / 10000),
            'generations' => $this->thisMonth()->count(),
            'ai_usd' => self::money($this->aiThisMonth()->sum('cost_usd')),
            'ai_calls' => $this->aiThisMonth()->count(),
            'by_person' => $byPerson,
            'updated_by_name' => $setting->updated_by_name,
            'updated_at' => $setting->updated_at?->toIso8601String(),
        ];
    }

    public function setCap(float $usd, ?string $uniId, ?string $name): void
    {
        $setting = CreativeSetting::current();
        $setting->forceFill([
            'monthly_cap_usd' => round($usd, 2),
            'updated_by_uni_id' => $uniId,
            'updated_by_name' => $name,
        ]);
        // A save that changes nothing still records who confirmed the cap.
        $setting->updated_at = now();
        $setting->save();
    }

    public function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfMonth();
    }

    /** Generations AND AI calls: one month total, one cap. */
    private function spent(): float
    {
        return (float) $this->thisMonth()->sum('charged_usd') + (float) $this->aiThisMonth()->sum('cost_usd');
    }

    private function thisMonth(): Builder
    {
        $start = $this->monthStart();

        return CreativeGeneration::query()
            ->where('created_at', '>=', $start->toDateTimeString())
            ->where('created_at', '<', $start->addMonth()->toDateTimeString());
    }

    private function aiThisMonth(): Builder
    {
        $start = $this->monthStart();

        return CreativeAiCall::query()
            ->where('created_at', '>=', $start->toDateTimeString())
            ->where('created_at', '<', $start->addMonth()->toDateTimeString());
    }

    private function fits(float $cap, float $spent, float $usd): bool
    {
        $capUnits = self::units($cap);

        return $capUnits > 0 && self::units($spent) + self::units($usd) <= $capUnits;
    }

    private static function units(mixed $usd): int
    {
        return (int) round(((float) $usd) * 10000);
    }

    private static function money(mixed $usd): float
    {
        return round((float) $usd, 4);
    }
}
