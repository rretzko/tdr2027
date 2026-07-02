<?php

declare(strict_types=1);

namespace App\Enums;

enum AuditionType: string
{
    case InPerson = 'in_person';
    case Remote = 'remote';

    public function label(): string
    {
        return match ($this) {
            self::InPerson => 'In Person',
            self::Remote => 'Remote',
        };
    }
}
