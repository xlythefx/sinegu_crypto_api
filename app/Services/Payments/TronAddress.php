<?php

namespace App\Services\Payments;

/**
 * Base58check for TRON addresses.
 *
 * WHY THIS EXISTS RATHER THAN A REGEX. `^T[1-9A-HJ-NP-Za-km-z]{33}$` matches a
 * typo: mutate one character of a real address and it still passes. A typo'd
 * RECEIVING address is the worst failure this feature can have — customers'
 * money goes to an address nobody holds the key to, and nothing in the system
 * notices, because the watcher simply reports zero transfers forever. The
 * checksum is the only thing that catches it, so validation has to decode.
 *
 * A TRON address is base58(payload ‖ checksum) where payload is 0x41 followed
 * by 20 address bytes, and checksum is the first 4 bytes of
 * sha256(sha256(payload)). 25 bytes decoded, always.
 *
 * No dependency: bcmath (already required by Invoice::feeCents()) does the
 * base conversion.
 */
final class TronAddress
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    /** Mainnet address prefix byte. Testnets use it too — only the chain differs. */
    private const PREFIX = "\x41";

    public static function isValid(?string $address): bool
    {
        return $address !== null && self::decode($address) !== null;
    }

    /**
     * The 21-byte payload (0x41 ‖ 20 address bytes), or null when the string is
     * not a well-formed, checksum-valid TRON address.
     */
    public static function decode(?string $address): ?string
    {
        if ($address === null) {
            return null;
        }

        // No trimming: a stored address with stray whitespace is a
        // configuration error we want surfaced, not silently repaired.
        $raw = self::base58Decode($address);
        if ($raw === null || strlen($raw) !== 25) {
            return null;
        }

        $payload = substr($raw, 0, 21);
        $checksum = substr($raw, 21, 4);

        if ($payload[0] !== self::PREFIX) {
            return null;
        }

        $expected = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);

        return hash_equals($expected, $checksum) ? $payload : null;
    }

    /**
     * Exact base58 comparison. NEVER case-folded — base58 is case-significant,
     * so folding both fails to match legitimately different addresses and
     * weakens the check on the one comparison that decides whether a transfer
     * is money (the token contract).
     */
    public static function equals(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null || $a === '' || $b === '') {
            return false;
        }

        return hash_equals($a, $b);
    }

    /**
     * Some TronGrid endpoints return the 41-prefixed hex form instead of
     * base58. Convert so everything downstream compares one representation.
     */
    public static function fromHex(?string $hex): ?string
    {
        if ($hex === null) {
            return null;
        }

        $hex = strtolower($hex);
        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }

        if (preg_match('/^41[0-9a-f]{40}$/', $hex) !== 1) {
            return null;
        }

        $payload = hex2bin($hex);
        $checksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);

        return self::base58Encode($payload.$checksum);
    }

    /**
     * Normalise whatever the chain handed us to base58, leaving an already-base58
     * value untouched. Returns null when it is neither.
     */
    public static function normalise(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (self::isValid($value)) {
            return $value;
        }

        return self::fromHex($value);
    }

    // ---- base58 ----------------------------------------------------------

    private static function base58Decode(string $input): ?string
    {
        if ($input === '') {
            return null;
        }

        $number = '0';
        $length = strlen($input);

        for ($i = 0; $i < $length; $i++) {
            $position = strpos(self::ALPHABET, $input[$i]);
            if ($position === false) {
                return null;
            }
            $number = bcadd(bcmul($number, '58', 0), (string) $position, 0);
        }

        $bytes = '';
        while (bccomp($number, '0', 0) > 0) {
            $bytes = chr((int) bcmod($number, '256')).$bytes;
            $number = bcdiv($number, '256', 0);
        }

        // Leading '1's encode leading zero bytes, which the arithmetic above
        // cannot represent.
        for ($i = 0; $i < $length && $input[$i] === '1'; $i++) {
            $bytes = "\x00".$bytes;
        }

        return $bytes;
    }

    private static function base58Encode(string $bytes): string
    {
        $number = '0';
        $length = strlen($bytes);

        for ($i = 0; $i < $length; $i++) {
            $number = bcadd(bcmul($number, '256', 0), (string) ord($bytes[$i]), 0);
        }

        $encoded = '';
        while (bccomp($number, '0', 0) > 0) {
            $encoded = self::ALPHABET[(int) bcmod($number, '58')].$encoded;
            $number = bcdiv($number, '58', 0);
        }

        for ($i = 0; $i < $length && $bytes[$i] === "\x00"; $i++) {
            $encoded = '1'.$encoded;
        }

        return $encoded;
    }
}
