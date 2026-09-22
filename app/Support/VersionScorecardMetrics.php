<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The Monday Morning Scorecard numbers for a single Version, computed by
 * VersionScorecardService and rendered by MondayMorningScorecardMail. All fee
 * amounts are cents; the *InDollars() helpers are for the email view only.
 */
final readonly class VersionScorecardMetrics
{
    public function __construct(
        public int $invitedTeachers,
        public int $invitedSchools,
        public int $eligibleStudents,
        public int $obligatedTeachers,
        public int $obligatedSchools,
        public int $engagedStudents,
        public int $registeredTeachers,
        public int $registeredSchools,
        public int $registeredStudents,
        public int $registrationFeesDueCents,
        public int $registrationFeesPaidCents,
        public int $registrationFeesOutstandingCents,
    ) {}

    public function registrationFeesDueInDollars(): float
    {
        return $this->registrationFeesDueCents / 100;
    }

    public function registrationFeesPaidInDollars(): float
    {
        return $this->registrationFeesPaidCents / 100;
    }

    public function registrationFeesOutstandingInDollars(): float
    {
        return $this->registrationFeesOutstandingCents / 100;
    }
}
