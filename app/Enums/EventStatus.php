<?php

declare(strict_types=1);

namespace App\Enums;

enum EventStatus: string
{
    case Sandbox = 'sandbox';
    case Active = 'active';
    case Inactive = 'inactive';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Sandbox => 'Sandbox',
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Closed => 'Closed',
        };
    }
}
