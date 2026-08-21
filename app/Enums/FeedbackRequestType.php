<?php

declare(strict_types=1);

namespace App\Enums;

enum FeedbackRequestType: string
{
    case Bug = 'bug';
    case Enhancement = 'enhancement';
    case Kudo = 'kudo';
    case Comment = 'comment';

    public function label(): string
    {
        return match ($this) {
            self::Bug => 'Bug',
            self::Enhancement => 'Enhancement',
            self::Kudo => 'Kudo',
            self::Comment => 'Comment',
        };
    }
}
