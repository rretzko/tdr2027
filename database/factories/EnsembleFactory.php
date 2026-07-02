<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ensemble;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ensemble>
 */
class EnsembleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'event_id' => Event::factory(),
            'name' => $name,
            'short_name' => null,
            'abbreviation' => strtoupper(substr((string) $name, 0, 3)),
        ];
    }
}
