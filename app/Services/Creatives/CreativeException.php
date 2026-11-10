<?php

namespace App\Services\Creatives;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal the Creatives page shows as-is, in the house error shape
 * `{success: false, error_code, message, …extra}`. Rendered by Laravel
 * through render(); not reported — these are answers, not faults.
 */
final class CreativeException extends RuntimeException
{
    /** @param  array<string, mixed>  $extra  e.g. `budget`, `generation` */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('NOT_CONFIGURED', 'Higgsfield is not connected on this server yet.', 503);
    }

    public static function notFound(string $what = 'Not found.'): self
    {
        return new self('NOT_FOUND', $what, 404);
    }

    /** @param  array<string, mixed>  $extra */
    public function with(array $extra): self
    {
        return new self($this->errorCode, $this->getMessage(), $this->httpStatus, $extra + $this->extra);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
        ] + $this->extra, $this->httpStatus);
    }

    /** Answered to the person; nothing for the error log. */
    public function report(): void {}
}
