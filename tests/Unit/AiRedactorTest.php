<?php

namespace Tests\Unit;

use App\Services\Ai\AiRedactor;
use PHPUnit\Framework\TestCase;

/**
 * The AI connector's second wall: a field is dropped by its NAME at any
 * depth. Contact details joined it on 2026-11-16 with the WhatsApp number —
 * admin user detail carries a `contacts` block, and a tool that reuses that
 * payload must not hand a customer's phone to the AI provider.
 */
class AiRedactorTest extends TestCase
{
    public function test_contact_details_are_dropped_at_any_depth(): void
    {
        $clean = AiRedactor::clean([
            'name' => 'Juan Dela Cruz',
            'profile' => [
                'contacts' => ['telegram' => 'juan', 'whatsapp' => '+639171234567', 'discord' => 'juan.dc'],
                'whatsapp' => '+639171234567',
                'phone' => '+639171234567',
                'mobile_phone' => '+639171234567',
                'discord_username' => 'juan.dc',
            ],
        ]);

        $this->assertSame(['name' => 'Juan Dela Cruz', 'profile' => []], $clean);
    }

    /**
     * A key a full admin allowed to see contacts (2026-11-26) keeps the
     * Telegram username — and only it: the phone, WhatsApp, Discord and the
     * `contacts` block are dropped whatever the key.
     */
    public function test_an_allowed_key_keeps_the_telegram_username_only(): void
    {
        $row = [
            'name' => 'Juan Dela Cruz',
            'telegram_username' => '@juan',
            'discord_username' => 'juan.dc',
            'whatsapp' => '+639171234567',
            'contacts' => ['telegram' => 'juan'],
            'email' => 'juan@example.com',
        ];

        $this->assertSame(['name' => 'Juan Dela Cruz'], AiRedactor::clean($row));
        $this->assertSame(
            ['name' => 'Juan Dela Cruz', 'telegram_username' => '@juan'],
            AiRedactor::clean($row, AiRedactor::CONTACT_FIELDS),
        );
    }

    public function test_counts_keyed_by_a_platform_name_survive(): void
    {
        // "Where did you hear about us" counts are keyed by source slug; a
        // count is not a contact, so the bare platform names are kept.
        $sources = ['telegram' => 4, 'discord' => 2, 'tiktok' => 9];

        $this->assertSame($sources, AiRedactor::clean($sources));
    }
}
