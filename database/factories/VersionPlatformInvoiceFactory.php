<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlatformInvoiceStatus;
use App\Models\Version;
use App\Models\VersionPlatformInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VersionPlatformInvoice>
 */
class VersionPlatformInvoiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version_id' => Version::factory(),
            'status' => PlatformInvoiceStatus::Pending->value,
            'send_at' => now()->addDay(),
        ];
    }

    public function sent(string $number = 'TDR-2026-0001', int $registrants = 10, int $rateCents = 275): static
    {
        return $this->state(fn (): array => [
            'status' => PlatformInvoiceStatus::Sent->value,
            'number' => $number,
            'send_at' => now()->subHour(),
            'sent_at' => now()->subHour(),
            'issued_at' => now()->subHour(),
            'due_at' => now()->subHour()->addDays(30),
            'registrant_count' => $registrants,
            'rate_cents' => $rateCents,
            'amount_cents' => $registrants * $rateCents,
            'recipients' => ['em@example.com'],
        ]);
    }
}
