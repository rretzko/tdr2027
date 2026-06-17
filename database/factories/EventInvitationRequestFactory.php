<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EventInvitationStatus;
use App\Models\Event;
use App\Models\EventInvitationRequest;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventInvitationRequest>
 */
class EventInvitationRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'teacher_id' => Teacher::factory(),
            'status' => EventInvitationStatus::Pending,
        ];
    }
}
