<?php

declare(strict_types=1);

namespace App\Enums;

enum ApplicationType: string
{
    case EApplication = 'eapplication';
    case Pdf = 'pdf';

    public function label(): string
    {
        return match ($this) {
            self::EApplication => 'E-Application',
            self::Pdf => 'PDF',
        };
    }
}
