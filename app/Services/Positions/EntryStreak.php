<?php

namespace App\Services\Positions;

/**
 * Whether streak sizing set a trade's size — the five columns an entry's tag
 * (`open_strategies`) carries to its closed trade (`{x}_pastpositions`).
 * The engine records them at ENTRY, the only moment the answer is known; they
 * are never reconstructed from history, because the ladder can change since.
 *
 * Two audiences. Staff read every figure (`forStaff`). A customer reads only
 * the kind, the run and whether the size went down or up (`forCustomer`):
 * base_size and the ladder's sizes are deliberately never in a trader payload
 * (same rule as the trader asset catalog).
 */
final class EntryStreak
{
    public const COLUMNS = ['streak_kind', 'streak_run', 'streak_step', 'streak_size', 'streak_base_size'];

    /** Validation rules for the five fields, each under $prefix ('' or 'rows.*.'). */
    public static function rules(string $prefix = ''): array
    {
        return [
            "{$prefix}streak_kind" => ['sometimes', 'nullable', 'string', 'in:loss,win'],
            "{$prefix}streak_run" => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            "{$prefix}streak_step" => ['sometimes', 'nullable', 'integer', 'min:1', 'max:255'],
            "{$prefix}streak_size" => ['sometimes', 'nullable', 'numeric', 'min:0'],
            "{$prefix}streak_base_size" => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * The five columns out of a validated payload, absent ones as null. A
     * payload without a kind carries no streak at all, so a stray size on its
     * own is never stored as half an answer.
     *
     * @return array<string, mixed>
     */
    public static function fromPayload(array $data): array
    {
        if (empty($data['streak_kind'])) {
            return array_fill_keys(self::COLUMNS, null);
        }

        return [
            'streak_kind' => $data['streak_kind'],
            'streak_run' => $data['streak_run'] ?? null,
            'streak_step' => $data['streak_step'] ?? null,
            'streak_size' => $data['streak_size'] ?? null,
            'streak_base_size' => $data['streak_base_size'] ?? null,
        ];
    }

    /**
     * Every figure, for staff screens. Null when the trade was sized normally.
     *
     * @return array{kind: string, run: ?int, step: ?int, size: ?float, base_size: ?float, effect: string}|null
     */
    public static function forStaff(object $row): ?array
    {
        if (empty($row->streak_kind)) {
            return null;
        }

        return [
            'kind' => $row->streak_kind,
            'run' => $row->streak_run === null ? null : (int) $row->streak_run,
            'step' => $row->streak_step === null ? null : (int) $row->streak_step,
            'size' => $row->streak_size === null ? null : (float) $row->streak_size,
            'base_size' => $row->streak_base_size === null ? null : (float) $row->streak_base_size,
            'effect' => self::effect($row),
        ];
    }

    /**
     * What a customer may read: the kind, the run, and smaller / larger /
     * same — never a size. Null when the trade was sized normally.
     *
     * @return array{kind: string, run: ?int, effect: string}|null
     */
    public static function forCustomer(object $row): ?array
    {
        $staff = self::forStaff($row);

        return $staff === null ? null : [
            'kind' => $staff['kind'],
            'run' => $staff['run'],
            'effect' => $staff['effect'],
        ];
    }

    /** smaller | larger | same — the step's size against the base it replaced. */
    private static function effect(object $row): string
    {
        if ($row->streak_size === null || $row->streak_base_size === null) {
            return 'same';
        }
        $size = (float) $row->streak_size;
        $base = (float) $row->streak_base_size;
        if (abs($size - $base) < 1e-9) {
            return 'same';
        }

        return $size < $base ? 'smaller' : 'larger';
    }
}
