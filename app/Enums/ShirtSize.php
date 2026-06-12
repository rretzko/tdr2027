<?php

declare(strict_types=1);

namespace App\Enums;

enum ShirtSize: string
{
    case XXS = 'xxs';
    case XS = 'xs';
    case SM = 'sm';
    case MED = 'med';
    case LG = 'lg';
    case XL = 'xl';
    case XXL = 'xxl';

    public function label(): string
    {
        return match ($this) {
            self::XXS => 'XX-Small',
            self::XS => 'X-Small',
            self::SM => 'Small',
            self::MED => 'Medium',
            self::LG => 'Large',
            self::XL => 'X-Large',
            self::XXL => 'XX-Large',
        };
    }
}
