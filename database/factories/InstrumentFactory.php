<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Instrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Instrument>
 */
class InstrumentFactory extends Factory
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
            'abbr' => fake()->unique()->lexify('????'),
            'family' => fake()->randomElement(['Woodwind', 'Brass', 'Percussion', 'String', 'Keyboard']),
            'in_band' => fake()->boolean(),
            'in_orchestra' => fake()->boolean(),
            'sort_order' => fake()->unique()->numberBetween(1, 1000),
        ];
    }
}
