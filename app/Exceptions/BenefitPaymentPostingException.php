<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by BenefitPaymentPoster when a follow-up payment cannot be posted
 * to a benefit (wrong status, amount exceeds remaining balance, already
 * fully paid, etc). The controller turns this into a 422 response.
 */
class BenefitPaymentPostingException extends RuntimeException
{
    //
}
