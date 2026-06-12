<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PhoneType;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Phone>
 */
class PhoneFactory extends Factory
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
            'type' => PhoneType::Cell,
            'raw_number' => fake()->numerify('##########'),
        ];
    }
}
