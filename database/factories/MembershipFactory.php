<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'teacher_id' => Teacher::factory(),
            'organization_id' => Organization::factory(),
            'membership_number' => fake()->numerify('MBR-#####'),
            'membership_expires_at' => fake()->dateTimeBetween('now', '+2 years')->format('Y-m-d'),
            'membership_card' => null,
        ];
    }
}
