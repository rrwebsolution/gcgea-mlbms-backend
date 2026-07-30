<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('drafts:cleanup-stale')->monthly();
Schedule::call(fn () => app(\App\Services\SystemBackupService::class)->runAutomaticIfDue())
    ->hourly()
    ->name('system-backups')
    ->withoutOverlapping();
