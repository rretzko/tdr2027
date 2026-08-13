<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentSource;
use App\Enums\PaymentTransactionStatus;
use App\Enums\Vendor;
use App\Models\PaymentTransaction;
use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentTransaction>
 */
class PaymentTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version_id' => Version::factory(),
            'source' => PaymentSource::Manual,
            'vendor' => null,
            'vendor_transaction_id' => null,
            'amount' => fake()->numberBetween(1000, 20000),
            'status' => PaymentTransactionStatus::Completed,
            'payment_type' => null,
            'paid_at' => now(),
        ];
    }

    public function candidateEpayment(): static
    {
        return $this->state([
            'source' => PaymentSource::CandidateEpayment,
            'vendor' => Vendor::Square,
            'vendor_transaction_id' => fake()->uuid(),
            'payment_type' => null,
            'status' => PaymentTransactionStatus::Pending,
            'paid_at' => null,
        ]);
    }

    public function teacherEpayment(): static
    {
        return $this->state([
            'source' => PaymentSource::TeacherEpayment,
            'vendor' => Vendor::Square,
            'vendor_transaction_id' => fake()->uuid(),
            'payment_type' => null,
            'status' => PaymentTransactionStatus::Pending,
            'paid_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state([
            'status' => PaymentTransactionStatus::Completed,
            'paid_at' => now(),
        ]);
    }
}
