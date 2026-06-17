<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SchoolType;
use App\Models\County;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' High School',
            'type' => SchoolType::School,
            'city' => fake()->city(),
            'zip_code' => fake()->numerify('#####'),
            'geostate_id' => null,
            'county_id' => County::factory(),
            'school_year' => 'US',
        ];
    }

    /**
     * Indicate that the school is a studio.
     */
    public function studio(): static
    {
        return $this->state(['type' => SchoolType::Studio]);
    }
}
