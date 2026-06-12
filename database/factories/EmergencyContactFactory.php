<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmergencyContactRelationship;
use App\Models\EmergencyContact;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmergencyContact>
 */
class EmergencyContactFactory extends Factory
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
            'name' => fake()->name(),
            'relationship' => EmergencyContactRelationship::Mother,
            'email' => fake()->unique()->safeEmail(),
            'cell_phone' => fake()->numerify('##########'),
            'home_phone' => null,
            'work_phone' => null,
        ];
    }
}
