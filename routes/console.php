<?php

use App\Console\Commands\ExpireAbandonedPaymentTransactions;
use App\Console\Commands\NotifyExpiringTeacherAccessWindows;
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
