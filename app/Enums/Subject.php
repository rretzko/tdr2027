<?php

declare(strict_types=1);

namespace App\Enums;

enum Subject: string
{
    case Band = 'band';
    case Chorus = 'chorus';
    case Orchestra = 'orchestra';

    public function label(): string
    {
        return match ($this) {
            self::Band => 'Band',
            self::Chorus => 'Chorus',
            self::Orchestra => 'Orchestra',
        };
    }
}
