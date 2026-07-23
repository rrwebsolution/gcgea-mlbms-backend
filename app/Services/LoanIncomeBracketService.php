<?php

namespace App\Services;

use App\Models\LoanType;
use App\Models\LoanTypeIncomeBracket;

/**
 * Tiers a LoanType's loanable amount by the member's monthly net take-home
 * pay, per GCGEA Board Resolution No. 24-2026 Table 3 (Solidarity Cash
 * Assistance Loan). A LoanType with no income_brackets rows is unaffected —
 * callers should fall back to its flat min/max_amount, same as before this
 * service existed.
 */
class LoanIncomeBracketService
{
    public function bracketFor(LoanType $loanType, float $netPay): ?LoanTypeIncomeBracket
    {
        return $loanType->incomeBrackets->first(
            fn (LoanTypeIncomeBracket $bracket) => $netPay >= (float) $bracket->min_net_pay
                && ($bracket->max_net_pay === null || $netPay <= (float) $bracket->max_net_pay)
        );
    }
}
