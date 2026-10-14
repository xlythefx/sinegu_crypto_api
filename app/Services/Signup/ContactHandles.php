<?php

namespace App\Services\Signup;

use App\Models\UserContactHandle;
use Illuminate\Http\Request;

/**
 * The optional Telegram / Discord usernames a user gives us — at sign-up
 * (AuthController::register; Telegram alone on DiscordAuthController::complete,
 * where Discord already knows the linked account) and later in Settings
 * (ContactHandleController). ONE normalisation for all three, so a handle
 * typed on the form and the same handle typed in Settings store identically.
 *
 * Normalise BEFORE validating (prepare() / normalized()): people paste a
 * t.me link or type the @, and both are the same username. What survives
 * normalisation is then checked against the platform's own username rules,
 * and a refusal is an ordinary field error under `telegram` / `discord`.
 *
 * Unverified and typed by the user, so NEVER unique across users: a
 * uniqueness rule would let one person block another by claiming their name
 * first. A user with neither handle has no row at all.
 */
final class ContactHandles
{
    public const TELEGRAM = 'telegram';

    public const DISCORD = 'discord';

    public const FIELDS = [self::TELEGRAM, self::DISCORD];

    /**
     * Telegram's rule: starts with a letter, then letters, digits, `_`. The
     * app asks for 5+ characters, but 4-character collectible names exist.
     */
    public const TELEGRAM_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{3,31}$/';

    /** Discord's post-2023 usernames: lowercase, digits, `_` and `.`. */
    public const DISCORD_PATTERN = '/^[a-z0-9_.]{2,32}$/';

    public const TELEGRAM_MESSAGE = 'Telegram usernames are 4–32 letters, numbers or underscores, e.g. @pixel_alpha.';

    public const DISCORD_MESSAGE = 'Discord usernames are 2–32 lowercase letters, numbers, dots or underscores.';

    /**
     * `@Pixel_Alpha`, `t.me/Pixel_Alpha`, `https://t.me/Pixel_Alpha` →
     * `Pixel_Alpha` (case kept — Telegram shows it as typed). Blank → null.
     * A non-string is returned untouched so validation refuses it rather than
     * this quietly coercing it.
     */
    public static function normalizeTelegram(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);
        $value = preg_replace('#^(?:https?://)?t\.me/#i', '', $value);
        $value = preg_replace('/^@/', '', $value);

        return $value === '' ? null : $value;
    }

    /** `@Foo.Bar` → `foo.bar` (Discord usernames are lowercase). Blank → null. */
    public static function normalizeDiscord(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = mb_strtolower(preg_replace('/^@/', '', trim($value)));

        return $value === '' ? null : $value;
    }

    /**
     * The normalised value of every listed field PRESENT in the input. An
     * absent field stays absent — on the Settings endpoint absent means
     * "unchanged", while null means "clear it".
     *
     * @param  list<string>  $fields
     */
    public static function normalized(array $input, array $fields = self::FIELDS): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) {
                $out[$field] = $field === self::TELEGRAM
                    ? self::normalizeTelegram($input[$field])
                    : self::normalizeDiscord($input[$field]);
            }
        }

        return $out;
    }

    /** Normalise the request in place, ahead of $request->validate(). */
    public static function prepare(Request $request, array $fields = self::FIELDS): void
    {
        $request->merge(self::normalized($request->all(), $fields));
    }

    /** @param  list<string>  $fields */
    public static function rules(array $fields = self::FIELDS): array
    {
        $rules = [
            self::TELEGRAM => ['nullable', 'string', 'regex:'.self::TELEGRAM_PATTERN],
            self::DISCORD => ['nullable', 'string', 'regex:'.self::DISCORD_PATTERN],
        ];

        return array_intersect_key($rules, array_flip($fields));
    }

    /** One plain sentence per field, whichever rule refused it. */
    public static function messages(): array
    {
        return [
            'telegram.string' => self::TELEGRAM_MESSAGE,
            'telegram.regex' => self::TELEGRAM_MESSAGE,
            'discord.string' => self::DISCORD_MESSAGE,
            'discord.regex' => self::DISCORD_MESSAGE,
        ];
    }

    /** @return array{telegram: ?string, discord: ?string} */
    public function forUser(string $uniId): array
    {
        $row = UserContactHandle::find($uniId);

        return [
            self::TELEGRAM => $row?->telegram,
            self::DISCORD => $row?->discord,
        ];
    }

    /**
     * Apply the fields present in $changes (already normalised and validated):
     * a value sets, null clears, an absent key is left as it is. The row goes
     * when both end up empty, so "no row" keeps meaning "no handles".
     *
     * @return array{telegram: ?string, discord: ?string} what is stored now
     */
    public function save(string $uniId, array $changes): array
    {
        $changes = array_intersect_key($changes, array_flip(self::FIELDS));
        $current = $this->forUser($uniId);

        if ($changes === []) {
            return $current;
        }

        $next = array_merge($current, $changes);

        if ($next[self::TELEGRAM] === null && $next[self::DISCORD] === null) {
            UserContactHandle::where('uni_id', $uniId)->delete();
        } else {
            UserContactHandle::updateOrCreate(['uni_id' => $uniId], $next);
        }

        return $next;
    }
}
