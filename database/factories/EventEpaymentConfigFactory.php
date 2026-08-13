<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Vendor;
use App\Models\Event;
use App\Models\EventEpaymentConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventEpaymentConfig>
 */
class EventEpaymentConfigFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'vendor' => Vendor::Square,
            'vendor_account_id' => fake()->uuid(),
            'secret' => fake()->sha256(),
            'webhook_signature_key' => fake()->sha256(),
        ];
    }

    public function disabled(): static
    {
        return $this->state([
            'vendor' => null,
            'vendor_account_id' => null,
            'secret' => null,
            'webhook_signature_key' => null,
        ]);
    }
}
