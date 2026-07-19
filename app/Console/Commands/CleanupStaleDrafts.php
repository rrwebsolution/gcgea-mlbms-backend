<?php

namespace App\Console\Commands;

use App\Models\BenefitApplication;
use App\Models\Loan;
use App\Models\Member;
use Illuminate\Console\Command;

/**
 * Deletes Draft-status members/loans/benefit applications that haven't been
 * touched in 90+ days. Never touches anything past Draft status.
 */
class CleanupStaleDrafts extends Command
{
    protected $signature = 'drafts:cleanup-stale {--days=90 : Age threshold in days}';

    protected $description = 'Delete abandoned drafts (members, loans, benefit applications) older than the given threshold';

    public function handle(): int
    {
        $threshold = now()->subDays((int) $this->option('days'));

        $memberIds = Member::where('is_draft', true)->where('updated_at', '<', $threshold)->pluck('id');
        $loanIds = Loan::where('status', 'Draft')->where('updated_at', '<', $threshold)->pluck('id');
        $benefitIds = BenefitApplication::where('status', 'Draft')->where('updated_at', '<', $threshold)->pluck('id');

        Member::whereIn('id', $memberIds)->each(fn (Member $member) => $member->delete());
        Loan::whereIn('id', $loanIds)->each(fn (Loan $loan) => $loan->delete());
        BenefitApplication::whereIn('id', $benefitIds)->each(fn (BenefitApplication $benefit) => $benefit->delete());

        $this->info(sprintf(
            'Cleaned up %d stale draft(s): %d member(s), %d loan(s), %d benefit application(s) untouched for %d+ days.',
            $memberIds->count() + $loanIds->count() + $benefitIds->count(),
            $memberIds->count(),
            $loanIds->count(),
            $benefitIds->count(),
            (int) $this->option('days')
        ));

        return self::SUCCESS;
    }
}
