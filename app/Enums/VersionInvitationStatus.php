<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * No "Eligible" case: eligibility is a computed pool (see
 * VersionInvitationEligibilityService), not a persisted row. A
 * version_invitations row only exists once a teacher has been invited.
 * Participating is reserved for the not-yet-built registration
 * workflow — this phase never writes it.
 *
 * Rejected is not terminal: VersionObligationResponseObserver moves the
 * invitation back to Obligated the moment the teacher re-accepts. It only
 * blocks new candidate enrollment and withdraws existing ones for as long
 * as the rejection stands (see CandidateService::withdrawAllForTeacherVersion()).
 */
enum VersionInvitationStatus: string
{
    case Invited = 'invited';
    case Obligated = 'obligated';
    case Rejected = 'rejected';
    case Participating = 'participating';

    public function label(): string
    {
        return match ($this) {
            self::Invited => 'Invited',
            self::Obligated => 'Obligated',
            self::Rejected => 'Rejected',
            self::Participating => 'Participating',
        };
    }
}
