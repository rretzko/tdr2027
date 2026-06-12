<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Geostate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Geostate>
 */
class GeostateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'country_abbr' => 'XX',
            'name' => fake()->unique()->city().' Test State',
            'abbr' => fake()->unique()->lexify('??'),
        ];
    }
}
