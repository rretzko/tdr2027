<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FeeType;
use App\Models\Candidate;
use App\Models\CandidateFeeWaiver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateFeeWaiver>
 */
class CandidateFeeWaiverFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_id' => Candidate::factory(),
            'fee_type' => FeeType::Registration->value,
            'reason' => fake()->sentence(),
            'waived_by_user_id' => null,
        ];
    }

    public function forFeeType(FeeType $feeType): static
    {
        return $this->state(fn (): array => ['fee_type' => $feeType->value]);
    }
}
