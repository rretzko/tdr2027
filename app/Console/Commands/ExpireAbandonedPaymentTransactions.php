<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PaymentTransactionStatus;
use App\Models\PaymentTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * A checkout session a teacher never finishes (Square Payment Link never
 * opened/completed; PayPal order created but never approved+captured)
 * generates no vendor webhook at all — see SquarePaymentGateway's and
 * PaypalPaymentGateway's own createCheckoutSession() docblocks — so the
 * payment_transactions row it left behind would otherwise sit at
 * status=pending forever: occupying VersionDashboard's "Pending Payments"
 * panel indefinitely, and never becoming eligible for allocation either
 * (see PaymentTransaction::isAllocatable()). This sweeps those up.
 *
 * A single bulk UPDATE ... WHERE status = pending, not a fetch-then-save
 * loop — the WHERE clause re-checks status at the same instant the row is
 * written, so a webhook that completes/fails/refunds a row concurrently
 * (mid-run) can never be clobbered by this command, the same guarantee a
 * fetch-then-check-then-save loop can't give.
 */
class ExpireAbandonedPaymentTransactions extends Command
{
    protected $signature = 'payments:expire-abandoned {--hours=8 : Age in hours after which an unconfirmed pending payment is marked expired}';

    protected $description = 'Mark payment_transactions rows still pending after --hours as expired, so abandoned checkouts stop appearing as awaiting-confirmation/allocatable.';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');

        $cutoff = now()->subHours($hours);

        $affected = PaymentTransaction::where('status', PaymentTransactionStatus::Pending->value)
            ->where('created_at', '<', $cutoff)
            ->update(['status' => PaymentTransactionStatus::Expired->value]);

        if ($affected > 0) {
            Log::info('ExpireAbandonedPaymentTransactions: marked abandoned pending payments as expired', [
                'hours' => $hours,
                'count' => $affected,
            ]);
        }

        $this->info("Expired {$affected} abandoned pending payment(s) older than {$hours} hour(s).");

        return self::SUCCESS;
    }
}
