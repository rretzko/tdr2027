<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Enums\Frequency;
use App\Models\Event;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->unique()->words(3, true),
            'short_name' => null,
            'logo_url' => null,
            'logo_alt' => null,
            'status' => EventStatus::Sandbox,
            'frequency' => Frequency::Annual,
            'audition_count' => 1,
            'ensemble_count' => 1,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => EventStatus::Active]);
    }
}
