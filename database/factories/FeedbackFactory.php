<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FeedbackRequestType;
use App\Enums\FeedbackStatus;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feedback>
 */
class FeedbackFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'from_page' => $this->faker->url(),
            'request_type' => FeedbackRequestType::Bug,
            'request' => $this->faker->sentence(),
            'file_path' => null,
            'is_private' => false,
            'status' => FeedbackStatus::Open,
        ];
    }
}
