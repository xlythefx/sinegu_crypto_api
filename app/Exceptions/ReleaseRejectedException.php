<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by ReferralService::release when any line fails re-validation
 * (already released, or no paid invoice behind it). The controller maps it to a
 * 422 and the WHOLE release request is rejected — never a partial write
 * (spec §2, release integrity). The message is user-facing.
 */
class ReleaseRejectedException extends RuntimeException
{
}
