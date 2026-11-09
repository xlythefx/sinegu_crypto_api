<?php

namespace App\Services\Ai;

/**
 * The last thing every AI tool answer passes through: drops any field whose
 * NAME says it is a credential or personal contact data, at any depth.
 *
 * The tools are built to never select those columns in the first place; this
 * is the second wall, for the tools that reuse an admin screen's payload (an
 * admin field added there tomorrow must not reach the AI provider by
 * accident). What it removes: exchange API keys and their hints, secrets,
 * passwords, tokens, reset / verification codes, emails, IP addresses,
 * browser strings, Discord ids. Names stay — the owner asked for real names.
 */
final class AiRedactor
{
    private const DROP = '/^(api_key|api_key_hint|api_keys|secret|secret_key|.*_secret|password|.*password.*|token|.*_token|tokens'
        .'|reset_code.*|verification_code.*|email|.*_email|emails|pending_email|ip|ip_address|.*_ip|ips'
        .'|user_agent|discord_id|server_ip|key_hash|cacert)$/i';

    public static function clean(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = json_decode(json_encode($value), true);
        }
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && preg_match(self::DROP, $k)) {
                continue;
            }
            $out[$k] = self::clean($v);
        }

        return $out;
    }
}
