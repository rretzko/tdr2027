<?php

declare(strict_types=1);

namespace App\Enums;

enum TeacherRole: string
{
    case Primary = 'primary';
    case Coteacher = 'coteacher';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Primary',
            self::Coteacher => 'Co-Teacher',
        };
    }
}
