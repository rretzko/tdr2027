<?php

declare(strict_types=1);

namespace App\Enums;

enum ClaimStatus: string
{
    case Approved = 'approved';
    case Pending = 'pending';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::Pending => 'Pending',
        };
    }
}
