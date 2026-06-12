<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\VoicePart;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VoicePart>
 */
class VoicePartFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'abbr' => fake()->unique()->lexify('???'),
            'sort_order' => fake()->unique()->numberBetween(1, 1000),
        ];
    }
}
