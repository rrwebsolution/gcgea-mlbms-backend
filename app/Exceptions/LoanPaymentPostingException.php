<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by LoanPaymentPoster when a payment cannot be posted to a loan
 * (wrong loan status, amount exceeds balance, etc). Callers decide how to
 * surface it — a single manual payment aborts the request, a payroll import
 * row instead turns it into a row-level validation reason.
 */
class LoanPaymentPostingException extends RuntimeException
{
    //
}
