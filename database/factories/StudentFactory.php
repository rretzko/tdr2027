<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ShirtSize;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'height' => fake()->numberBetween(48, 76),
            'birthday' => fake()->dateTimeBetween('-18 years', '-9 years')->format('Y-m-d'),
            'shirt_size' => ShirtSize::MED,
            'instrument_id' => null,
            'voice_part_id' => null,
        ];
    }
}
