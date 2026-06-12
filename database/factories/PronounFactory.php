<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Pronoun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pronoun>
 */
class PronounFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'description' => 'they/them/their/theirs/themself',
            'intensive' => 'themself',
            'personal' => 'they',
            'possessive' => 'theirs',
            'object' => 'theirs',
            'sort_order' => fake()->unique()->numberBetween(1, 1000),
        ];
    }
}
