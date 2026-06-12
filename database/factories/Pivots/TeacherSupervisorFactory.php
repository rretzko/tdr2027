<?php

declare(strict_types=1);

namespace Database\Factories\Pivots;

use App\Models\Organization;
use App\Models\Pivots\TeacherSupervisor;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeacherSupervisor>
 */
class TeacherSupervisorFactory extends Factory
{
    protected $model = TeacherSupervisor::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'teacher_id' => Teacher::factory(),
            'supervisor_name' => fake()->name(),
            'supervisor_email' => fake()->unique()->safeEmail(),
            'supervisory_cell_phone' => fake()->numerify('##########'),
        ];
    }
}
