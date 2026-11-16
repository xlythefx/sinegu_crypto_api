<?php

namespace App\Services\Signup;

use App\Models\UserContactHandle;
use App\Models\UserCredential;
use Illuminate\Http\Request;

/**
 * How the team reaches a user: Telegram username, WhatsApp number, Discord
 * username — given at sign-up (AuthController::register; Telegram + WhatsApp
 * on DiscordAuthController::complete, where Discord already knows the linked
 * account) and later in Settings (ContactHandleController). ONE normalisation
 * for every form, so a value typed at sign-up and in Settings store identically.
 *
 * **Telegram and WhatsApp are REQUIRED (owner, 2026-11-16)**: at sign-up, and
 * a CUSTOMER (`type = 'user'`) missing either may not connect an exchange
 * (`connectLocked`, checked in ExchangeAccountController::store → 422
 * CONTACTS_REQUIRED). Accounts already connected keep trading — the lock is on
 * connecting only, so an existing customer is asked, never cut off. In
 * Settings a required value may be changed but not cleared. Discord stays
 * optional.
 *
 * Normalise BEFORE validating (prepare() / normalized()): people paste a
 * t.me link or type the @, and both are the same username. What survives
 * normalisation is then checked against the platform's own rules, and a
 * refusal is an ordinary field error under `telegram` / `discord` / `whatsapp`.
 *
 * Unverified and typed by the user, so NEVER unique across users: a
 * uniqueness rule would let one person block another by claiming their name
 * (or number) first. A user with none of them has no row at all.
 */
final class ContactHandles
{
    public const TELEGRAM = 'telegram';

    public const DISCORD = 'discord';

    public const WHATSAPP = 'whatsapp';

    public const FIELDS = [self::TELEGRAM, self::DISCORD, self::WHATSAPP];

    /** What a customer must have given before connecting an exchange. */
    public const REQUIRED = [self::TELEGRAM, self::WHATSAPP];

    /**
     * Telegram's rule: starts with a letter, then letters, digits, `_`. The
     * app asks for 5+ characters, but 4-character collectible names exist.
     */
    public const TELEGRAM_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{3,31}$/';

    /** Discord's post-2023 usernames: lowercase, digits, `_` and `.`. */
    public const DISCORD_PATTERN = '/^[a-z0-9_.]{2,32}$/';

    /**
     * E.164: a `+`, a country code that never starts with 0, 7–15 digits in
     * all. The shape is all a typed number can be checked for.
     */
    public const WHATSAPP_PATTERN = '/^\+[1-9][0-9]{6,14}$/';

    public const TELEGRAM_MESSAGE = 'Telegram usernames are 4–32 letters, numbers or underscores, e.g. @pixel_alpha.';

    public const DISCORD_MESSAGE = 'Discord usernames are 2–32 lowercase letters, numbers, dots or underscores.';

    public const WHATSAPP_MESSAGE = 'Enter your WhatsApp number with its country code, e.g. +66 81 234 5678.';

    public const TELEGRAM_REQUIRED = 'Please add your Telegram username.';

    public const WHATSAPP_REQUIRED = 'Please add your WhatsApp number.';

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
     * `+66 81-234 5678`, `0066812345678`, `https://wa.me/66812345678` →
     * `+66812345678`. Spaces, dashes, dots and brackets go; a leading `00` is
     * the international prefix. A number with no country code keeps no `+`,
     * so validation refuses it rather than this guessing a country. Blank →
     * null; a non-string untouched, as above.
     */
    public static function normalizeWhatsapp(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);
        $link = '#^(?:https?://)?(?:api\.)?wa\.me/#i';
        if (preg_match($link, $value)) {
            $value = '+'.preg_replace($link, '', $value);
        }
        $value = preg_replace('/[\s\-.()\x{00A0}]/u', '', $value);
        $value = preg_replace('/^00/', '+', $value);

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
                $out[$field] = match ($field) {
                    self::TELEGRAM => self::normalizeTelegram($input[$field]),
                    self::WHATSAPP => self::normalizeWhatsapp($input[$field]),
                    default => self::normalizeDiscord($input[$field]),
                };
            }
        }

        return $out;
    }

    /** Normalise the request in place, ahead of $request->validate(). */
    public static function prepare(Request $request, array $fields = self::FIELDS): void
    {
        $request->merge(self::normalized($request->all(), $fields));
    }

    /**
     * `$signup`: the REQUIRED fields must be there (a sign-up form). Without
     * it (Settings) an absent key is still "unchanged", but a required one
     * that IS sent may not be empty — changed, never cleared.
     *
     * @param  list<string>  $fields
     */
    public static function rules(array $fields = self::FIELDS, bool $signup = false): array
    {
        $required = $signup ? ['required'] : ['sometimes', 'required'];

        $rules = [
            self::TELEGRAM => [...$required, 'string', 'regex:'.self::TELEGRAM_PATTERN],
            self::DISCORD => ['nullable', 'string', 'regex:'.self::DISCORD_PATTERN],
            self::WHATSAPP => [...$required, 'string', 'regex:'.self::WHATSAPP_PATTERN],
        ];

        return array_intersect_key($rules, array_flip($fields));
    }

    /** One plain sentence per field, whichever rule refused it. */
    public static function messages(): array
    {
        return [
            'telegram.string' => self::TELEGRAM_MESSAGE,
            'telegram.regex' => self::TELEGRAM_MESSAGE,
            'telegram.required' => self::TELEGRAM_REQUIRED,
            'discord.string' => self::DISCORD_MESSAGE,
            'discord.regex' => self::DISCORD_MESSAGE,
            'whatsapp.string' => self::WHATSAPP_MESSAGE,
            'whatsapp.regex' => self::WHATSAPP_MESSAGE,
            'whatsapp.required' => self::WHATSAPP_REQUIRED,
        ];
    }

    /** @return array{telegram: ?string, discord: ?string, whatsapp: ?string} */
    public function forUser(string $uniId): array
    {
        $row = UserContactHandle::find($uniId);

        return [
            self::TELEGRAM => $row?->telegram,
            self::DISCORD => $row?->discord,
            self::WHATSAPP => $row?->whatsapp,
        ];
    }

    /**
     * The REQUIRED fields this user has not given, in REQUIRED order.
     *
     * @param  array{telegram: ?string, discord: ?string, whatsapp: ?string}|null  $contacts  already read, to skip the query
     * @return list<string>
     */
    public function missingFor(string $uniId, ?array $contacts = null): array
    {
        $contacts ??= $this->forUser($uniId);

        return array_values(array_filter(self::REQUIRED, fn (string $f) => empty($contacts[$f])));
    }

    /**
     * Whether this user may not connect an exchange yet. Customers only:
     * staff, master and tester accounts are ours and are never locked out.
     *
     * @param  array{telegram: ?string, discord: ?string, whatsapp: ?string}|null  $contacts
     */
    public function connectLocked(UserCredential $user, ?array $contacts = null): bool
    {
        return $user->type === 'user' && $this->missingFor($user->uni_id, $contacts) !== [];
    }

    /**
     * Apply the fields present in $changes (already normalised and validated):
     * a value sets, null clears, an absent key is left as it is. The row goes
     * when all end up empty, so "no row" keeps meaning "no contacts".
     *
     * @return array{telegram: ?string, discord: ?string, whatsapp: ?string} what is stored now
     */
    public function save(string $uniId, array $changes): array
    {
        $changes = array_intersect_key($changes, array_flip(self::FIELDS));
        $current = $this->forUser($uniId);

        if ($changes === []) {
            return $current;
        }

        $next = array_merge($current, $changes);

        if ($next[self::TELEGRAM] === null && $next[self::DISCORD] === null && $next[self::WHATSAPP] === null) {
            UserContactHandle::where('uni_id', $uniId)->delete();
        } else {
            UserContactHandle::updateOrCreate(['uni_id' => $uniId], $next);
        }

        return $next;
    }
}
