<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Geostate;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionMailToAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VersionMailToAddress>
 */
class VersionMailToAddressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version_id' => Version::factory(),
            'user_id' => User::factory(),
            'recipient_name' => fake()->name(),
            'organization_line' => fake()->company(),
            'address_line1' => fake()->streetAddress(),
            'address_line2' => null,
            'city' => fake()->city(),
            'geostate_id' => Geostate::factory(),
            'zip' => fake()->postcode(),
        ];
    }
}
