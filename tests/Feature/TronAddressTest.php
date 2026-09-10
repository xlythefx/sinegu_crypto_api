<?php

namespace Tests\Feature;

use App\Services\Payments\TronAddress;
use App\Services\Payments\TronUnits;
use Tests\TestCase;

/**
 * The primitives that stand between a configuration mistake and money going
 * somewhere unrecoverable. No database, no HTTP.
 */
class TronAddressTest extends TestCase
{
    /** Tether's real USDT-TRC20 contract, verified on chain (name TetherToken). */
    private const USDT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    public function test_it_accepts_real_addresses(): void
    {
        $this->assertTrue(TronAddress::isValid(self::USDT));
        $this->assertTrue(TronAddress::isValid('TAUN6FwrnwwmaEqYcckffC7wYmbaS6cBiX'));
    }

    /**
     * The whole reason this is base58check and not a regex: a mutated address
     * still matches `^T[1-9A-HJ-NP-Za-km-z]{33}$`, and a typo'd RECEIVING
     * address loses customers' money silently — the watcher just reports zero
     * transfers forever.
     */
    public function test_it_rejects_every_single_character_typo(): void
    {
        $alphabet = str_split('123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz');
        $accepted = [];

        for ($i = 1; $i < strlen(self::USDT); $i++) {
            foreach ($alphabet as $char) {
                if (self::USDT[$i] === $char) {
                    continue;
                }
                $candidate = substr(self::USDT, 0, $i).$char.substr(self::USDT, $i + 1);
                if (TronAddress::isValid($candidate)) {
                    $accepted[] = $candidate;
                }
            }
        }

        $this->assertSame([], $accepted, 'A single-character typo passed the checksum.');
    }

    public function test_it_rejects_malformed_input(): void
    {
        $this->assertFalse(TronAddress::isValid(null));
        $this->assertFalse(TronAddress::isValid(''));
        $this->assertFalse(TronAddress::isValid('   '));
        // Whitespace is a configuration error we want surfaced, not trimmed away.
        $this->assertFalse(TronAddress::isValid(' '.self::USDT));
        $this->assertFalse(TronAddress::isValid(self::USDT.' '));
        $this->assertFalse(TronAddress::isValid(substr(self::USDT, 0, 33)));
        $this->assertFalse(TronAddress::isValid(self::USDT.'x'));
        // An EVM address and a bech32 string both look plausible to a human.
        $this->assertFalse(TronAddress::isValid('0x2170Ed0880ac9A755fd29B2688956BD959F933F8'));
        $this->assertFalse(TronAddress::isValid('bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq'));
        // '0' and 'O' are not in the base58 alphabet at all.
        $this->assertFalse(TronAddress::isValid('T0OIl'.substr(self::USDT, 5)));
    }

    public function test_hex_round_trips_to_base58(): void
    {
        $payload = TronAddress::decode(self::USDT);
        $this->assertNotNull($payload);
        $this->assertSame(21, strlen($payload));

        $hex = '41'.bin2hex(substr($payload, 1));
        $this->assertSame('41a614f803b6fd780986a42c78ec9c7f77e6ded13c', $hex);
        $this->assertSame(self::USDT, TronAddress::fromHex($hex));
        $this->assertSame(self::USDT, TronAddress::fromHex('0x'.$hex));
        $this->assertSame(self::USDT, TronAddress::normalise($hex));
        $this->assertSame(self::USDT, TronAddress::normalise(self::USDT));

        $this->assertNull(TronAddress::fromHex('deadbeef'));
        $this->assertNull(TronAddress::normalise('not-an-address'));
    }

    /**
     * base58 is case-significant. Folding case would both fail to match
     * legitimately different addresses and weaken the one comparison that
     * decides whether a transfer is money.
     */
    public function test_equality_is_exact_and_never_case_folded(): void
    {
        $this->assertTrue(TronAddress::equals(self::USDT, self::USDT));
        $this->assertFalse(TronAddress::equals(self::USDT, strtolower(self::USDT)));
        $this->assertFalse(TronAddress::equals(self::USDT, strtoupper(self::USDT)));
        $this->assertFalse(TronAddress::equals(self::USDT, null));
        $this->assertFalse(TronAddress::equals(null, null));
        $this->assertFalse(TronAddress::equals('', ''));
    }

    public function test_units_convert_through_bcmath(): void
    {
        $this->assertSame('12340000', TronUnits::centsToUnits(1234, 6));
        $this->assertSame('12.340000', TronUnits::format('12340000', 6));
        $this->assertSame('0.010000', TronUnits::format(TronUnits::centsToUnits(1, 6), 6));
        $this->assertSame('123.450000', TronUnits::format(TronUnits::centsToUnits(12345, 6), 6));
        $this->assertSame(12.34, TronUnits::toUsd('12340000', 6));

        // The value feeCents() exists to get right: '12.34500000' as a double is
        // 12.34499999…, so a float path returns 1234 cents and 12340000 units.
        $this->assertSame('12350000', TronUnits::centsToUnits(1235, 6));
    }

    public function test_percentages_floor_rather_than_round(): void
    {
        $this->assertSame('123400', TronUnits::pctUnits('12340000', 1.0));
        $this->assertSame('617000', TronUnits::pctUnits('12340000', 5.0));
        $this->assertSame('0', TronUnits::pctUnits('12340000', 0));
        $this->assertSame('0', TronUnits::pctUnits('1', 1.0));
    }

    /**
     * A hostile contract can emit a 256-bit value. Anything that will not fit a
     * BIGINT column must be recorded and then never arithmetic'd.
     */
    public function test_out_of_range_values_are_refused(): void
    {
        $this->assertTrue(TronUnits::isSafeInteger('0'));
        $this->assertTrue(TronUnits::isSafeInteger('9223372036854775807'));
        $this->assertFalse(TronUnits::isSafeInteger('9223372036854775808'));
        $this->assertFalse(TronUnits::isSafeInteger(str_repeat('9', 78)));
        $this->assertFalse(TronUnits::isSafeInteger('-1'));
        $this->assertFalse(TronUnits::isSafeInteger('12.5'));
        $this->assertFalse(TronUnits::isSafeInteger(''));
        $this->assertFalse(TronUnits::isSafeInteger(null));
    }
}
