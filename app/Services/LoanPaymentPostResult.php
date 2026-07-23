<?php

namespace App\Services;

use App\Models\LoanPayment;

/**
 * Result of LoanPaymentPoster::post() — carries the created payment plus the
 * loan's balance/status snapshot both before and after the write, which is
 * what lets payroll import rollback later verify nothing else touched the
 * loan in between.
 */
final class LoanPaymentPostResult
{
    /**
     * @param  array<int,string>  $warnings
     */
    public function __construct(
        public readonly LoanPayment $payment,
        public readonly float $principalBalanceBefore,
        public readonly float $interestBalanceBefore,
        public readonly string $statusBefore,
        public readonly float $principalBalanceAfter,
        public readonly float $interestBalanceAfter,
        public readonly string $statusAfter,
        public readonly array $warnings = [],
    ) {}
}
