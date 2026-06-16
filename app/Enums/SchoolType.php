<?php

declare(strict_types=1);

namespace App\Enums;

enum SchoolType: string
{
    case School = 'school';
    case Studio = 'studio';

    public function label(): string
    {
        return match ($this) {
            self::School => 'School',
            self::Studio => 'Studio',
        };
    }
}
