<?php

namespace App\Providers;

use App\Models\BenefitApplication;
use App\Models\Loan;
use App\Models\Member;
use App\Policies\ApprovalPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Loan::class, ApprovalPolicy::class);
        Gate::policy(BenefitApplication::class, ApprovalPolicy::class);
        Gate::policy(Member::class, ApprovalPolicy::class);
    }
}
