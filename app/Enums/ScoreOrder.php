<?php

declare(strict_types=1);

namespace App\Enums;

enum ScoreOrder: string
{
    case Asc = 'asc';
    case Desc = 'desc';

    public function label(): string
    {
        return match ($this) {
            self::Asc => 'Ascending (lower is better)',
            self::Desc => 'Descending (higher is better)',
        };
    }
}
