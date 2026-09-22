<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateStatus;
use App\Enums\FeeType;
use App\Enums\ObligationDecision;
use App\Enums\PaymentTransactionStatus;
use App\Models\Candidate;
use App\Models\PaymentAllocation;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Support\VersionScorecardMetrics;

/**
 * Computes the INVITATIONS / ENGAGED / REGISTERED / FEES numbers for the
 * Monday Morning Scorecard email (SendMondayMorningScorecardEmails). Reuses
 * the same "invited"/"eligible" rules as the Registrations screens
 * (VersionInvitationEligibilityService, EligibilityService) rather than
 * re-deriving them, so the scorecard can never drift from what a teacher or
 * Event Manager actually sees in the app.
 */
class VersionScorecardService
{
    public function __construct(
        private readonly VersionInvitationEligibilityService $invitationEligibility,
        private readonly EligibilityService $eligibility,
    ) {}

    public function metricsFor(Version $version): VersionScorecardMetrics
    {
        $invitedTeachers = $this->invitationEligibility->invitedTeachers($version);

        $invitedSchoolIds = $invitedTeachers
            ->flatMap(fn (Teacher $teacher) => $teacher->schools->pluck('id'))
            ->unique();

        // excludeEnrolled: false — the scorecard wants the whole eligible pool
        // size, not "who's still available to add" (eligibleStudents()'s
        // default), which would otherwise collapse toward zero as the
        // Version fills up with Candidates.
        $eligibleStudentIds = $invitedTeachers
            ->flatMap(fn (Teacher $teacher) => $this->eligibility->eligibleStudents($version, $teacher, excludeEnrolled: false)->pluck('id'))
            ->unique();

        $obligatedTeacherIds = VersionInvitation::where('version_id', $version->id)
            ->whereHas('obligationResponse', fn ($q) => $q->where('decision', ObligationDecision::Accepted->value))
            ->pluck('teacher_id')
            ->unique();

        $obligatedSchoolIds = Teacher::whereIn('id', $obligatedTeacherIds)
            ->with(['schools' => function ($query): void {
                $query->wherePivot('is_active', true)->whereNotNull('school_teacher.verified_at');
            }])
            ->get()
            ->flatMap(fn (Teacher $teacher) => $teacher->schools->pluck('id'))
            ->unique();

        $engagedStudents = Candidate::where('version_id', $version->id)
            ->whereIn('status', [CandidateStatus::Pending->value, CandidateStatus::Registered->value])
            ->count();

        $registeredCandidates = Candidate::where('version_id', $version->id)
            ->where('status', CandidateStatus::Registered->value)
            ->get(['id', 'teacher_id', 'school_id']);

        $registeredStudents = $registeredCandidates->count();

        $fees = $version->fees;
        $registrationFeeCents = $fees !== null ? $fees->registration : 0;
        $registrationFeesDueCents = $registrationFeeCents * $registeredStudents;

        // payment_allocations.amount is already each candidate's settled
        // portion of its parent transaction (never a running total to add
        // payment_transactions.amount on top of — that would double-count
        // the same dollars). Scoped by the transaction's own version_id/
        // status/fee_type rather than the $registeredCandidates id list, so
        // a payment made before/after a candidate's status was Registered
        // still counts as collected.
        $registrationFeesPaidCents = (int) PaymentAllocation::whereHas(
            'paymentTransaction',
            fn ($q) => $q->where('version_id', $version->id)
                ->where('status', PaymentTransactionStatus::Completed->value)
                ->where('fee_type', FeeType::Registration->value),
        )->sum('amount');

        return new VersionScorecardMetrics(
            invitedTeachers: $invitedTeachers->count(),
            invitedSchools: $invitedSchoolIds->count(),
            eligibleStudents: $eligibleStudentIds->count(),
            obligatedTeachers: $obligatedTeacherIds->count(),
            obligatedSchools: $obligatedSchoolIds->count(),
            engagedStudents: $engagedStudents,
            registeredTeachers: $registeredCandidates->pluck('teacher_id')->unique()->count(),
            registeredSchools: $registeredCandidates->pluck('school_id')->unique()->count(),
            registeredStudents: $registeredStudents,
            registrationFeesDueCents: $registrationFeesDueCents,
            registrationFeesPaidCents: $registrationFeesPaidCents,
            registrationFeesOutstandingCents: $registrationFeesDueCents - $registrationFeesPaidCents,
        );
    }
}
