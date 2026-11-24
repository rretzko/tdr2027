<?php

declare(strict_types=1);

namespace App\Support\RegistrationHistory;

use Carbon\CarbonInterface;

/** One dated line of a registration history. */
final readonly class HistoryEntry
{
    public function __construct(
        public CarbonInterface $at,
        public string $description,
        public ?string $actor = null,
        public ?string $student = null,
        public bool $isDeadline = false,
    ) {}

    public function displayAt(): string
    {
        return $this->at->copy()->timezone((string) config('app.display_timezone'))->format('Y-m-d h:i:s A');
    }
}
