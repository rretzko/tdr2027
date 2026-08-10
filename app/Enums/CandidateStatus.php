<?php

declare(strict_types=1);

namespace App\Enums;

enum CandidateStatus: string
{
    case Eligible = 'eligible';
    case Pending = 'pending';
    case Registered = 'registered';
    case Withdrew = 'withdrew';
    case TeacherWithdrawn = 'teacher_withdrawn';
    case Adjudicated = 'adjudicated';
    case NoShow = 'no_show';
    case Incomplete = 'incomplete';
    case Accepted = 'accepted';
    case NotAccepted = 'not_accepted';
    case Declined = 'declined';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Eligible => 'Eligible',
            self::Pending => 'Pending',
            self::Registered => 'Registered',
            self::Withdrew => 'Withdrew',
            self::TeacherWithdrawn => 'Teacher Withdrawn',
            self::Adjudicated => 'Adjudicated',
            self::NoShow => 'No Show',
            self::Incomplete => 'Incomplete',
            self::Accepted => 'Accepted',
            self::NotAccepted => 'Not Accepted',
            self::Declined => 'Declined',
            self::Removed => 'Removed',
        };
    }

    /** @return list<self> */
    public static function registrationStates(): array
    {
        return [self::Eligible, self::Pending, self::Registered];
    }

    /**
     * Every state a Candidate can be in while still meaningfully "in" a
     * Room's roster for Tab Room tracking purposes: still awaiting a
     * decision (Registered), or already resolved by Ensemble Cut-offs
     * (NoShow/Incomplete/Accepted/NotAccepted). Used by
     * AdjudicationService::candidatesForRoom() so a Room's roster and
     * progress don't silently lose a Candidate the moment a cutoff is
     * applied for their Voice Part — see EnsembleCutoffService, which
     * writes exactly these four resolved states.
     *
     * @return list<self>
     */
    public static function roomTrackingStates(): array
    {
        return [self::Registered, self::NoShow, self::Incomplete, self::Accepted, self::NotAccepted];
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Eligible, self::Pending, self::Registered], true);
    }
}
