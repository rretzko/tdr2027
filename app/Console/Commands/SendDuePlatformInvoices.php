<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PlatformInvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sends platform invoices whose 24h post-Close Audition delay has elapsed.
 * Scheduled every 15 minutes instead of a delayed queued job because SQS on
 * Vapor caps job delay at 15 minutes — see PlatformInvoiceService.
 * vapor.yml runs the scheduler in production only.
 */
class SendDuePlatformInvoices extends Command
{
    protected $signature = 'invoices:send-due';

    protected $description = 'Email platform-usage invoices to Event Managers once 24 hours have passed since Close Audition.';

    public function handle(PlatformInvoiceService $invoices): int
    {
        $tally = $invoices->sendDue();

        if ($tally !== []) {
            Log::info('SendDuePlatformInvoices: processed due platform invoices', $tally);
        }

        $summary = $tally === []
            ? 'none due'
            : collect($tally)->map(fn (int $count, string $outcome): string => "{$outcome}: {$count}")->implode(', ');

        $this->info("Platform invoices processed ({$summary}).");

        return self::SUCCESS;
    }
}
