<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Per-row result of matching a director CSV row against existing records
 * (docs/plans/director-csv-upload.md §3). PossibleDuplicate is the only
 * outcome that blocks the upload's Confirm step until the Event Manager
 * resolves it.
 */
enum DirectorRowOutcome: string
{
    case New = 'new';
    case Existing = 'existing';
    case AlreadyInvited = 'already_invited';
    case PossibleDuplicate = 'possible_duplicate';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Existing => 'Existing',
            self::AlreadyInvited => 'Already invited',
            self::PossibleDuplicate => 'Needs review',
            self::Error => 'Error',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'green',
            self::Existing => 'blue',
            self::AlreadyInvited => 'zinc',
            self::PossibleDuplicate => 'amber',
            self::Error => 'red',
        };
    }
}
