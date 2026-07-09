<?php

declare(strict_types=1);

namespace App\Enums;

enum ObligationDecision: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
        };
    }
}
