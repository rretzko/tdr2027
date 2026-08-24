<?php

declare(strict_types=1);

namespace App\Enums;

enum LoginMethod: string
{
    case Email = 'email';
    case CellPhone = 'cell_phone';
    case Google = 'google';
    case Facebook = 'facebook';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::CellPhone => 'Cell Phone',
            self::Google => 'Google',
            self::Facebook => 'Facebook',
        };
    }
}
