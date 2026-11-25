<?php

namespace App\Services\Signup;

use App\Models\UserSignupSource;
use Illuminate\Validation\Rule;

/**
 * "Where did you hear about us?" — the optional multi-select on the sign-up
 * forms (password register and Discord complete).
 *
 * One row per ticked option in `user_signup_sources`, so the statistics that
 * come later are a GROUP BY rather than a CSV split. The slugs are the stored
 * values AND the API contract with the frontend's checkbox list: they are
 * stable and never renamed — a renamed slug splits one source's history in
 * two. A new option is a new slug appended here and on the form.
 */
final class SignupSources
{
    /** The nine options, in the order the form shows them; `other` is last. */
    public const SLUGS = ['discord', 'telegram', 'ai', 'friend', 'youtube', 'x', 'google', 'social', 'other'];

    /**
     * What each slug reads as to a person: the form's own tile labels, kept in
     * step with `src/lib/signupSources.ts` (SIGNUP_SOURCES) in the frontend.
     * Relabel here and there together; never rename a slug.
     */
    public const LABELS = [
        'discord' => 'Discord server',
        'telegram' => 'Telegram',
        'ai' => 'AI chatbot',
        'friend' => 'Friend / referral',
        'youtube' => 'YouTube',
        'x' => 'X (Twitter)',
        'google' => 'Google search',
        'social' => 'TikTok / Instagram',
        'other' => 'Other',
    ];

    /** The one option that carries free text. */
    public const OTHER = 'other';

    /** Longest free text kept for `other`. */
    public const DETAIL_MAX = 120;

    /**
     * Validation rules for the two request fields. Both optional: the question
     * may be skipped entirely. An unknown or repeated slug is a 422 — it means
     * the client is out of step with this list, and storing it would put a
     * value no statistic knows into the table.
     */
    public static function rules(): array
    {
        return [
            'heard_from' => ['nullable', 'array', 'max:'.count(self::SLUGS)],
            'heard_from.*' => ['string', 'distinct', Rule::in(self::SLUGS)],
            'heard_from_other' => ['nullable', 'string', 'max:'.self::DETAIL_MAX],
        ];
    }

    /**
     * Store the ticked options for a new user. Meant to run inside the
     * registration transaction, so a failed sign-up leaves no answers behind.
     * The free text is kept on the `other` row only, and only when `other`
     * was ticked — text under an unticked box is a leftover, not an answer.
     *
     * @param  list<string>  $slugs  already validated against SLUGS
     */
    public function store(string $uniId, array $slugs, ?string $otherDetail): void
    {
        $detail = trim((string) $otherDetail);
        $now = now();

        $rows = [];
        foreach (array_intersect(self::SLUGS, $slugs) as $slug) {
            $rows[] = [
                'uni_id' => $uniId,
                'source' => $slug,
                'detail' => $slug === self::OTHER && $detail !== '' ? $detail : null,
                'created_at' => $now,
            ];
        }

        if ($rows !== []) {
            UserSignupSource::insert($rows);
        }
    }

    /**
     * The answers as one line a person reads: "YouTube, Other: a forum".
     * Null when nothing was ticked (the question is optional).
     *
     * @param  list<array{source: string, detail: ?string}>  $answers  from forUser()
     */
    public static function describe(array $answers): ?string
    {
        $parts = [];
        foreach ($answers as $answer) {
            $label = self::LABELS[$answer['source']] ?? $answer['source'];
            $parts[] = $answer['detail'] !== null && $answer['detail'] !== ''
                ? "{$label}: {$answer['detail']}"
                : $label;
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * A user's answers in SLUGS order (the form's order), never insertion order.
     *
     * @return list<array{source: string, detail: ?string}>
     */
    public function forUser(string $uniId): array
    {
        $order = array_flip(self::SLUGS);

        return UserSignupSource::where('uni_id', $uniId)
            ->get(['source', 'detail'])
            ->sortBy(fn (UserSignupSource $row) => $order[$row->source] ?? PHP_INT_MAX)
            ->map(fn (UserSignupSource $row) => [
                'source' => $row->source,
                'detail' => $row->detail,
            ])
            ->values()
            ->all();
    }
}
