<?php

declare(strict_types=1);

namespace App\Services;

use App\Concerns\HasCandidateChecklist;
use App\Enums\CandidateStatus;
use App\Enums\FeeType;
use App\Enums\PaymentTransactionStatus;
use App\Enums\VersionDateType;
use App\Enums\VersionInvitationStatus;
use App\Models\Candidate;
use App\Models\PaymentAllocation;
use App\Models\VersionDate;
use App\Models\VersionInvitation;
use App\Support\DirectorWeeklyUpdate;

/**
 * Computes one director's Tuesday participation update
 * (SendDirectorWeeklyUpdateEmails). The candidate roster is the same one the
 * director sees on their Registrations dashboard — their own candidates plus
 * any visible via a co-teaching grant (CoTeacherAccessService::candidateQuery())
 * — and "missing requirements" are the same checklist items
 * (HasCandidateChecklist) that drive CandidateService::recalculateStatus(),
 * so the email can never disagree with what the director sees in the app.
 */
class DirectorWeeklyUpdateService
{
    use HasCandidateChecklist;

    public const AUDITION_CAP_HOLD = 'Held at Pending: your school is over its audition cap for this event';

    public function __construct(
        private readonly CoTeacherAccessService $coTeacherAccess,
        private readonly EligibilityService $eligibility,
    ) {}

    public function updateFor(VersionInvitation $invitation): DirectorWeeklyUpdate
    {
        $version = $invitation->version;
        $teacher = $invitation->teacher;

        $candidates = $this->coTeacherAccess->candidateQuery($teacher)
            ->where('version_id', $version->id)
            ->whereIn('status', [CandidateStatus::Pending->value, CandidateStatus::Registered->value])
            ->with(['student.user', 'student.homeAddress', 'student.emergencyContacts', 'uploadFiles', 'voicePart', 'version'])
            ->get()
            ->sortBy(fn (Candidate $candidate): string => mb_strtolower($candidate->student->user->sort_name))
            ->values();

        $pending = $candidates->where('status', CandidateStatus::Pending)->values();
        $registered = $candidates->where('status', CandidateStatus::Registered)->values();

        $checklistDefs = $this->checklistDefs($version);

        $pendingRoster = $pending->map(function (Candidate $candidate) use ($checklistDefs): array {
            $missing = collect($checklistDefs)
                ->reject(fn (array $item): bool => ($item['check'])($candidate))
                ->pluck('label')
                ->values()
                ->all();

            // A fully-checklisted candidate is only ever Pending because of
            // AuditionCapService — see CandidateService::recalculateStatus().
            if ($missing === []) {
                $missing = [self::AUDITION_CAP_HOLD];
            }

            return [
                'name' => (string) $candidate->student->user->name,
                'voicePart' => (string) $candidate->voicePart?->name,
                'missing' => $missing,
            ];
        })->all();

        $registeredRoster = $registered->map(fn (Candidate $candidate): array => [
            'name' => (string) $candidate->student->user->name,
            'voicePart' => (string) $candidate->voicePart?->name,
        ])->all();

        /** @var list<VersionDate> $dates */
        $dates = $version->dates()
            ->whereIn('date_type', [
                VersionDateType::Candidate->value,
                VersionDateType::PostmarkDeadline->value,
                VersionDateType::Adjudication->value,
            ])
            ->orderBy('start_at')
            ->get()
            ->all();

        $fees = $version->fees;
        $registrationFeeCents = $fees !== null ? $fees->registration : 0;
        $participationFeeCents = $fees !== null ? $fees->participation : 0;

        // Same "Paid" computation as VersionDashboard's $paidByCandidateId,
        // narrowed to registration-fee transactions on this Version. Scoped
        // to every one of the director's candidates (not just Registered),
        // so a fee paid ahead of checklist completion still counts.
        $paidCents = (int) PaymentAllocation::whereIn(
            'candidate_id',
            $this->coTeacherAccess->candidateQuery($teacher)->where('version_id', $version->id)->select('id'),
        )
            ->whereHas('paymentTransaction', fn ($q) => $q->where('version_id', $version->id)
                ->where('status', PaymentTransactionStatus::Completed->value)
                ->where('fee_type', FeeType::Registration->value))
            ->sum('amount');

        return new DirectorWeeklyUpdate(
            version: $version,
            invitationStatus: VersionInvitationStatus::from($invitation->getRawOriginal('status')),
            dates: $dates,
            eligibleStudents: $this->eligibility->eligibleStudents($version, $teacher, excludeEnrolled: false)->count(),
            pendingStudents: $pending->count(),
            registeredStudents: $registered->count(),
            registrationFeeCents: $registrationFeeCents,
            participationFeeCents: $participationFeeCents,
            dueCents: $registered->count() * $registrationFeeCents,
            paidCents: $paidCents,
            pendingRoster: $pendingRoster,
            registeredRoster: $registeredRoster,
        );
    }
}
