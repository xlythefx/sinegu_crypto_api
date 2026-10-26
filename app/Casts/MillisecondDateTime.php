<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A UTC datetime stored WITH milliseconds (a DATETIME(3) column).
 *
 * The built-in `datetime` cast writes through the model's date format,
 * 'Y-m-d H:i:s', so the fraction is dropped before MySQL ever sees it.
 * Changing the model's date format instead would also round every other
 * datetime on it (trade_logs.ts is DATETIME(0), and MySQL ROUNDS a fraction
 * it cannot keep — 23:59:59.7 would land on the next day).
 */
class MillisecondDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse((string) $value, 'UTC');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $at = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value, 'UTC');

        return $at->utc()->format('Y-m-d H:i:s.v');
    }
}
