<?php

namespace App\Services\Ai\Tools;

/**
 * JSON Schema pieces the tools share, so "from" means the same thing in every
 * tool's published schema.
 */
final class Props
{
    /** @param array<string, array<string, mixed>> $properties */
    public static function object(array $properties, array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => (object) $properties, 'additionalProperties' => false];
        if ($required) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    public static function from(string $default): array
    {
        return ['type' => 'string', 'description' => "First day, YYYY-MM-DD, UTC. Default: {$default}."];
    }

    public static function to(): array
    {
        return ['type' => 'string', 'description' => 'Last day (included), YYYY-MM-DD, UTC. Default: today.'];
    }

    public static function exchange(): array
    {
        return ['type' => 'string', 'enum' => ['binance', 'bybit', 'mexc'], 'description' => 'One exchange only. Default: all three.'];
    }

    public static function coin(): array
    {
        return ['type' => 'string', 'description' => 'Coin, e.g. "LTC" (LTCUSDT and LTC_USDT also work). Default: every coin.'];
    }

    public static function owner(): array
    {
        return ['type' => 'string', 'description' => 'Part of a person\'s name or account name, e.g. "Juan". Default: everyone.'];
    }

    public static function who(string $default): array
    {
        return [
            'type' => 'string',
            'enum' => ['all', 'master', 'customers', 'staff'],
            'description' => "Whose accounts: master = the house account the strategy runs on; customers = paying users; staff = admins, developers, testers. Default: {$default}.",
        ];
    }

    public static function limit(int $default, int $max): array
    {
        return ['type' => 'integer', 'minimum' => 1, 'maximum' => $max, 'description' => "Rows to return (max {$max}). Default: {$default}."];
    }

    public static function offset(): array
    {
        return ['type' => 'integer', 'minimum' => 0, 'description' => 'Rows to skip, for the next page. Default: 0.'];
    }
}
