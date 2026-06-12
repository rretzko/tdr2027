<?php

declare(strict_types=1);

namespace App\Enums;

enum PhoneType: string
{
    case Cell = 'cell';
    case Home = 'home';
    case Work = 'work';

    public function label(): string
    {
        return match ($this) {
            self::Cell => 'Cell',
            self::Home => 'Home',
            self::Work => 'Work',
        };
    }
}
