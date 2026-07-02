<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VoicePart;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Candidate>
 */
class CandidateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'version_id' => Version::factory(),
            'school_id' => School::factory(),
            'teacher_id' => Teacher::factory(),
            'voice_part_id' => VoicePart::factory(),
            'status' => CandidateStatus::Eligible,
            'program_name' => fake()->name(),
            'emergency_contact_id' => null,
        ];
    }

    public function registered(): static
    {
        return $this->state(['status' => CandidateStatus::Registered]);
    }
}
