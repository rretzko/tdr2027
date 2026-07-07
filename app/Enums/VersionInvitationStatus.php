<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * No "Eligible" case: eligibility is a computed pool (see
 * VersionInvitationEligibilityService), not a persisted row. A
 * version_invitations row only exists once a teacher has been invited.
 * Obligated/Participating are reserved for the not-yet-built registration
 * workflow — this phase never writes them.
 */
enum VersionInvitationStatus: string
{
    case Invited = 'invited';
    case Obligated = 'obligated';
    case Participating = 'participating';

    public function label(): string
    {
        return match ($this) {
            self::Invited => 'Invited',
            self::Obligated => 'Obligated',
            self::Participating => 'Participating',
        };
    }
}
