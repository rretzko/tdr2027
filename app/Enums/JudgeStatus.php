<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Default is Assigned at RoomJudge creation. The remaining cases are
 * day-of transitions belonging to the not-yet-built Adjudication phase
 * (see event-version-orientation.md §5.9) — no UI writes them yet.
 */
enum JudgeStatus: string
{
    case Assigned = 'assigned';
    case Exempt = 'exempt';
    case Completed = 'completed';
    case Delegated = 'delegated';
    case LeftEarly = 'left_early';
    case NoShow = 'no_show';
    case Substitute = 'substitute';

    public function label(): string
    {
        return match ($this) {
            self::Assigned => 'Assigned',
            self::Exempt => 'Exempt',
            self::Completed => 'Completed',
            self::Delegated => 'Delegated',
            self::LeftEarly => 'Left Early',
            self::NoShow => 'No Show',
            self::Substitute => 'Substitute',
        };
    }
}
