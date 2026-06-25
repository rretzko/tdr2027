<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PageVisit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageVisit>
 */
class PageVisitFactory extends Factory
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
            'route_name' => 'dashboard',
            'label' => 'Dashboard',
            'visit_count' => 1,
            'last_visited_at' => fake()->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
