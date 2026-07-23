<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a payroll import batch cannot be rolled back because something
 * it created has drifted from the state the import left it in (a later
 * payment, a manual void, etc). Rollback is refuse-on-drift and all-or-
 * nothing per batch — this exception carries the full list of drifted rows
 * in its message so the admin knows exactly what to reverse manually.
 */
class PayrollImportRollbackException extends RuntimeException
{
    //
}
