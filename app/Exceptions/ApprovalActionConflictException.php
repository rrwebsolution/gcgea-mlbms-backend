<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an approval action can no longer be applied because the
 * subject's approval instance has already moved on (double-submit races,
 * or acting on a stale/cached view of the record). Mapped to HTTP 409 in
 * bootstrap/app.php.
 */
class ApprovalActionConflictException extends RuntimeException
{
    //
}
