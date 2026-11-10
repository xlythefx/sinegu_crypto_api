<?php

namespace App\Services\Creatives;

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
 * The month is the UTC calendar month of a generation's `created_at`; the
 * month's spend is SUM(charged_usd). A generation reserves its estimate the
 * moment it is created (charged_usd = estimated_usd) and gives it back only
 * when Higgsfield says it ended without a result — so a pending job always
 * counts against the cap.
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
        CreativeSetting::current();

        return DB::transaction(function () use ($usd, $create) {
            // The lock is the FIRST read of the transaction: InnoDB takes its
            // snapshot at the first plain read, so the SUM below — after the
            // lock was granted — sees every generation committed before it.
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

    /** The Budget shape of the API contract, for the current month. */
    public function summary(): array
    {
        $setting = CreativeSetting::current();
        $cap = (float) $setting->monthly_cap_usd;
        $spent = $this->spent();

        $byPerson = $this->thisMonth()->toBase()
            ->selectRaw('created_by_uni_id, MAX(created_by_name) AS name, SUM(charged_usd) AS spent, COUNT(*) AS n')
            ->groupBy('created_by_uni_id')
            ->orderByDesc('spent')
            ->get()
            ->map(fn ($row) => [
                'uni_id' => $row->created_by_uni_id,
                'name' => $row->name,
                'spent_usd' => self::money($row->spent),
                'generations' => (int) $row->n,
            ])
            ->values()
            ->all();

        return [
            'month' => $this->monthStart()->format('Y-m'),
            'cap_usd' => round($cap, 2),
            'spent_usd' => self::money($spent),
            'reserved_usd' => self::money($this->thisMonth()->whereIn('status', CreativeGeneration::PENDING)->sum('charged_usd')),
            'remaining_usd' => self::money(max(0, self::units($cap) - self::units($spent)) / 10000),
            'generations' => $this->thisMonth()->count(),
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

    private function spent(): float
    {
        return (float) $this->thisMonth()->sum('charged_usd');
    }

    private function thisMonth(): Builder
    {
        $start = $this->monthStart();

        return CreativeGeneration::query()
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
