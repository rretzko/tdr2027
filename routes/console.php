<?php

use App\Console\Commands\ExpireAbandonedPaymentTransactions;
use App\Console\Commands\NotifyExpiringTeacherAccessWindows;
use App\Console\Commands\SendMondayMorningScorecardEmails;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// vapor.yml enables the scheduler in production only (staging: false) — see
// ExpireAbandonedPaymentTransactions's own docblock for why this exists.
Schedule::command(ExpireAbandonedPaymentTransactions::class)->hourly();
Schedule::command(NotifyExpiringTeacherAccessWindows::class)->daily();

// Storage timezone is UTC (config/app.php); this fires at 7:30am America/New_York
// wall-clock time every Monday regardless of DST, per config/app.php's
// display_timezone convention.
Schedule::command(SendMondayMorningScorecardEmails::class)
    ->weeklyOn(1, '7:30')
    ->timezone('America/New_York');
