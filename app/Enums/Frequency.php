<?php

declare(strict_types=1);

namespace App\Enums;

enum Frequency: string
{
    case Annual = 'annual';
    case Biannual = 'biannual';
    case Biennial = 'biennial';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Annual => 'Annual',
            self::Biannual => 'Biannual',
            self::Biennial => 'Biennial',
            self::Monthly => 'Monthly',
        };
    }
}
