<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\PaymentSource;
use App\Enums\PaymentTransactionStatus;
use App\Enums\Vendor;
use App\Models\PaymentTransaction;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireAbandonedPaymentTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private function makePending(Version $version, string $referenceNumber, \DateTimeInterface $createdAt): PaymentTransaction
    {
        $transaction = PaymentTransaction::create([
            'version_id' => $version->id,
            'source' => PaymentSource::TeacherEpayment,
            'vendor' => Vendor::Square,
            'vendor_transaction_id' => $referenceNumber,
            'amount' => 5000,
            'status' => PaymentTransactionStatus::Pending,
        ]);

        // created_at isn't mass-assignable via create() above (not in the
        // model's Fillable list) — set it directly, same as the age this
        // command actually keys off.
        $transaction->forceFill(['created_at' => $createdAt])->save();

        return $transaction->fresh();
    }

    public function test_expires_pending_transactions_older_than_the_default_8_hours(): void
    {
        $version = Version::factory()->create();
        $old = $this->makePending($version, 'ORDER-OLD', now()->subHours(9));
        $recent = $this->makePending($version, 'ORDER-RECENT', now()->subHours(2));

        $this->artisan('payments:expire-abandoned')
            ->expectsOutputToContain('Expired 1 abandoned pending payment')
            ->assertSuccessful();

        expect($old->refresh()->status)->toBe(PaymentTransactionStatus::Expired);
        expect($recent->refresh()->status)->toBe(PaymentTransactionStatus::Pending);
    }

    public function test_a_transaction_exactly_at_the_cutoff_boundary_is_not_yet_expired(): void
    {
        $version = Version::factory()->create();
        $boundary = $this->makePending($version, 'ORDER-BOUNDARY', now()->subHours(8));

        $this->artisan('payments:expire-abandoned')->assertSuccessful();

        expect($boundary->refresh()->status)->toBe(PaymentTransactionStatus::Pending);
    }

    public function test_the_hours_option_overrides_the_default(): void
    {
        $version = Version::factory()->create();
        $threeHoursOld = $this->makePending($version, 'ORDER-3H', now()->subHours(3));

        $this->artisan('payments:expire-abandoned', ['--hours' => 2])->assertSuccessful();

        expect($threeHoursOld->refresh()->status)->toBe(PaymentTransactionStatus::Expired);
    }

    public function test_completed_failed_and_refunded_transactions_are_left_alone_regardless_of_age(): void
    {
        $version = Version::factory()->create();

        $completed = PaymentTransaction::create([
            'version_id' => $version->id,
            'source' => PaymentSource::TeacherEpayment,
            'vendor' => Vendor::Square,
            'vendor_transaction_id' => 'ORDER-COMPLETED',
            'amount' => 5000,
            'status' => PaymentTransactionStatus::Completed,
        ]);
        $completed->forceFill(['created_at' => now()->subDays(5)])->save();

        $failed = PaymentTransaction::create([
            'version_id' => $version->id,
            'source' => PaymentSource::TeacherEpayment,
            'vendor' => Vendor::Square,
            'vendor_transaction_id' => 'ORDER-FAILED',
            'amount' => 5000,
            'status' => PaymentTransactionStatus::Failed,
        ]);
        $failed->forceFill(['created_at' => now()->subDays(5)])->save();

        $this->artisan('payments:expire-abandoned')->assertSuccessful();

        expect($completed->refresh()->status)->toBe(PaymentTransactionStatus::Completed);
        expect($failed->refresh()->status)->toBe(PaymentTransactionStatus::Failed);
    }

    public function test_reports_zero_expired_when_nothing_is_abandoned(): void
    {
        $version = Version::factory()->create();
        $this->makePending($version, 'ORDER-RECENT', now()->subHours(1));

        $this->artisan('payments:expire-abandoned')
            ->expectsOutputToContain('Expired 0 abandoned pending payment')
            ->assertSuccessful();
    }
}
