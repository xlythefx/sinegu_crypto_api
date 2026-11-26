<?php

namespace App\Services\Ai;

use App\Models\AiAccessKey;

/**
 * The last thing every AI tool answer passes through: drops any field whose
 * NAME says it is a credential or personal contact data, at any depth.
 *
 * The tools are built to never select those columns in the first place; this
 * is the second wall, for the tools that reuse an admin screen's payload (an
 * admin field added there tomorrow must not reach the AI provider by
 * accident). What it removes: exchange API keys and their hints, secrets,
 * passwords, tokens, reset / verification codes, emails, IP addresses,
 * browser strings, Discord ids, and how to reach a person — the `contacts`
 * block (Telegram, Discord, WhatsApp), phone numbers and the usernames on
 * their own. Names stay — the owner asked for real names.
 *
 * ONE exception, per key (2026-11-26, Dima's Grok): a key a full admin
 * allowed to see contacts (`ai_access_keys.can_see_contacts`) keeps the
 * fields in CONTACT_FIELDS — the Telegram username, and nothing else. A
 * WhatsApp number, an email or a phone number is dropped for every key.
 */
final class AiRedactor
{
    private const DROP = '/^(api_key|api_key_hint|api_keys|secret|secret_key|.*_secret|password|.*password.*|token|.*_token|tokens'
        .'|reset_code.*|verification_code.*|email|.*_email|emails|pending_email|ip|ip_address|.*_ip|ips'
        .'|user_agent|discord_id|server_ip|key_hash|cacert'
        .'|contacts|whatsapp|.*_whatsapp|phone|.*_phone|phone_.*|discord_username|telegram_username|telegram_handle)$/i';

    /**
     * What a key allowed to see contacts keeps. Never a WhatsApp number, an
     * email or a phone. (A bare `telegram` key is NOT a contact — it is a
     * "where did you hear about us" count — which is why the users tool names
     * the username `telegram_username`, a name DROP removes for every other key.)
     */
    public const CONTACT_FIELDS = ['telegram_username'];

    /** @return list<string> the dropped field names this key may still receive */
    public static function allowedFor(?AiAccessKey $key): array
    {
        return $key !== null && $key->can_see_contacts ? self::CONTACT_FIELDS : [];
    }

    /** @param  list<string>  $allow  field names kept although DROP matches them (lower case) */
    public static function clean(mixed $value, array $allow = []): mixed
    {
        if (is_object($value)) {
            $value = json_decode(json_encode($value), true);
        }
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && preg_match(self::DROP, $k) && ! in_array(strtolower($k), $allow, true)) {
                continue;
            }
            $out[$k] = self::clean($v, $allow);
        }

        return $out;
    }
}
