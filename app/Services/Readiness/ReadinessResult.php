<?php

declare(strict_types=1);

namespace App\Services\Readiness;

use App\Enums\ReadinessStatus;

final readonly class ReadinessResult
{
    public function __construct(
        public ReadinessItem $item,
        public ReadinessStatus $status,
        public ?string $detail,
        public string $url,
        public bool $canAcknowledge,
    ) {}

    public function blocks(): bool
    {
        return $this->item->blocking && $this->status->isIncomplete();
    }
}
