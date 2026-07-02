<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\EventStatus;
use App\Enums\PitchFileVisibility;
use App\Enums\ScoreOrder;
use App\Enums\UploadType;
use App\Models\Event;
use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Version>
 */
class VersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'name' => fake()->year().' '.fake()->words(2, true),
            'short_name' => null,
            'senior_class_of' => (int) date('Y') + 1,
            'status' => EventStatus::Sandbox,
            'application_type' => ApplicationType::Pdf,
            'audition_timeslot' => 20,
            'audition_type' => AuditionType::Remote,
            'birthday' => false,
            'emergency_contact_name' => true,
            'emergency_contact_cell' => true,
            'emergency_contact_email' => false,
            'height' => false,
            'home_address' => false,
            'judge_count' => 1,
            'max_registrants' => null,
            'max_upper_voice_registrants' => null,
            'pitch_file_visibility' => PitchFileVisibility::Both,
            'release_confidential_results' => false,
            'score_order' => ScoreOrder::Asc,
            'shirt_size' => false,
            'teacher_cell' => true,
            'upload_type' => UploadType::None,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => EventStatus::Active]);
    }

    public function inPerson(): static
    {
        return $this->state([
            'audition_type' => AuditionType::InPerson,
            'audition_timeslot' => 20,
        ]);
    }
}
