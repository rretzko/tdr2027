<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\HomeAddress;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HomeAddress>
 */
class HomeAddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'address1' => fake()->streetAddress(),
            'address2' => null,
            'city' => fake()->city(),
            'geo_state' => 'NJ',
            'zip_code' => fake()->numerify('#####'),
        ];
    }
}
