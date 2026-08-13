<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Version;
use App\Models\VersionEpaymentConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VersionEpaymentConfig>
 */
class VersionEpaymentConfigFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version_id' => Version::factory(),
            'epayment_student' => false,
            'epayment_teacher' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state([
            'epayment_student' => false,
            'epayment_teacher' => false,
        ]);
    }
}
