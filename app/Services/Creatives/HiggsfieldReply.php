<?php

namespace App\Services\Creatives;

/**
 * What one Higgsfield call came back with, in the three cases the callers
 * must tell apart:
 *
 *  - OK         — a 2xx; `data` is the JSON body.
 *  - REFUSED    — a 4xx: Higgsfield answered and said no. Nothing was started
 *                 and nothing is charged. `detail` is Higgsfield's own text.
 *  - NO_ANSWER  — a transport error or a 5xx: we do not know what happened.
 *                 For a submit that matters: the job may exist and cost money.
 */
final class HiggsfieldReply
{
    public const OK = 'ok';

    public const REFUSED = 'refused';

    public const NO_ANSWER = 'no_answer';

    /** @param  array<string, mixed>  $data */
    private function __construct(
        public readonly string $outcome,
        public readonly ?int $status,
        public readonly array $data,
        public readonly ?string $detail,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function ok(int $status, array $data): self
    {
        return new self(self::OK, $status, $data, null);
    }

    public static function refused(int $status, ?string $detail): self
    {
        return new self(self::REFUSED, $status, [], $detail);
    }

    public static function noAnswer(?int $status = null): self
    {
        return new self(self::NO_ANSWER, $status, [], null);
    }

    public function isOk(): bool
    {
        return $this->outcome === self::OK;
    }

    public function isRefused(): bool
    {
        return $this->outcome === self::REFUSED;
    }

    public function isNoAnswer(): bool
    {
        return $this->outcome === self::NO_ANSWER;
    }
}
