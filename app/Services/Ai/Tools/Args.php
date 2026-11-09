<?php

namespace App\Services\Ai\Tools;

use Carbon\Carbon;

/**
 * A tool call's arguments, read defensively: the AI writes them, so every
 * value is checked and a bad one is a ToolError that tells it what to send.
 */
final class Args
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values) {}

    public function str(string $key, ?string $default = null, int $max = 120): ?string
    {
        $v = $this->values[$key] ?? null;
        if ($v === null || $v === '') {
            return $default;
        }
        if (! is_scalar($v)) {
            throw new ToolError("{$key} must be text.");
        }

        return mb_substr(trim((string) $v), 0, $max);
    }

    /** @param list<string> $allowed */
    public function enum(string $key, array $allowed, ?string $default = null): ?string
    {
        $v = $this->str($key);
        if ($v === null) {
            return $default;
        }
        $v = strtolower($v);
        foreach ($allowed as $option) {
            if (strtolower($option) === $v) {
                return $option;
            }
        }

        throw new ToolError("{$key} must be one of: ".implode(', ', $allowed).'.');
    }

    public function int(string $key, int $default, int $min, int $max): int
    {
        $v = $this->values[$key] ?? null;
        if ($v === null || $v === '') {
            return $default;
        }
        if (! is_numeric($v)) {
            throw new ToolError("{$key} must be a number.");
        }

        return max($min, min($max, (int) $v));
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->values[$key] ?? null;
        if ($v === null || $v === '') {
            return $default;
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function date(string $key): ?string
    {
        $v = $this->str($key);
        if ($v === null) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if (! $d || $d->format('Y-m-d') !== $v) {
            throw new ToolError("{$key} must be a date written YYYY-MM-DD (UTC), e.g. 2026-11-01.");
        }

        return $v;
    }

    public function month(string $key): ?string
    {
        $v = $this->str($key);
        if ($v === null) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m', $v);
        if (! $d || $d->format('Y-m') !== $v) {
            throw new ToolError("{$key} must be a month written YYYY-MM, e.g. 2026-10.");
        }

        return $v;
    }

    /**
     * from / to as UTC dates. Missing `to` = today; missing `from` = `to`
     * minus $defaultDays − 1 (so the window holds $defaultDays days).
     *
     * @return array{0: string, 1: string}
     */
    public function range(int $defaultDays): array
    {
        $to = $this->date('to') ?? Carbon::now('UTC')->toDateString();
        $from = $this->date('from')
            ?? Carbon::parse($to, 'UTC')->subDays(max($defaultDays - 1, 0))->toDateString();
        if ($from > $to) {
            throw new ToolError('from must be on or before to.');
        }

        return [$from, $to];
    }
}
