<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TrackablePage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrackablePage>
 */
class TrackablePageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'route_name' => 'dashboard',
            'label' => 'Dashboard',
            'is_active' => true,
        ];
    }
}
