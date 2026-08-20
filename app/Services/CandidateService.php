<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Version;

class CandidateService
{
    /**
     * Enroll a student in a version. The CandidateObserver handles:
     * - ID generation (version_id + 4-digit suffix → id + ref)
     * - program_name defaulting to user's first + last name
     * - Initial CandidateStatusHistory entry
     *
     * @throws \RuntimeException if the school cannot be resolved
     */
    public function enroll(
        Version $version,
        Student $student,
        Teacher $teacher,
        int $schoolId,
        int $voicePartId,
    ): Candidate {
        // Redirected to a consolidated co-teacher if one is set for this
        // (version, school, teacher) — docs/plans/co-teacher-definition.md §4.
        // Resolved via the container, not a constructor dependency, so the
        // many existing `new CandidateService` call sites (this class has no
        // constructor today) don't all need updating for one extra lookup.
        $recordedTeacher = app(CoTeacherConsolidationService::class)->resolveTeacherId($version, $schoolId, $teacher);

        return Candidate::create([
            'student_id' => $student->id,
            'version_id' => $version->id,
            'school_id' => $schoolId,
            'teacher_id' => $recordedTeacher->id,
            'voice_part_id' => $voicePartId,
            'status' => CandidateStatus::Eligible->value,
            'program_name' => '',
            'emergency_contact_id' => null,
        ]);
    }

    /**
     * Teacher-initiated withdrawal. Records history via the observer's
     * updating() hook when status changes.
     */
    public function withdraw(Candidate $candidate): void
    {
        $candidate->update(['status' => CandidateStatus::TeacherWithdrawn->value]);
    }

    /**
     * Student-initiated withdrawal (studentfolder-module.md §5.4) — distinct
     * status from withdraw()'s TeacherWithdrawn so the two remain
     * distinguishable in reporting/history, even though both are terminal
     * pre-adjudication states.
     */
    public function withdrawByCandidate(Candidate $candidate): void
    {
        $candidate->update(['status' => CandidateStatus::Withdrew->value]);
    }

    /**
     * Iron-gate cascade: when a teacher rejects a Version's obligations,
     * every candidate they've enrolled for that Version that's still in an
     * active state is withdrawn — full stop to their participation. Each
     * withdrawal goes through withdraw() so CandidateObserver's history
     * trail records it like any other teacher-initiated withdrawal.
     */
    public function withdrawAllForTeacherVersion(int $versionId, int $teacherId): void
    {
        Candidate::where('version_id', $versionId)
            ->where('teacher_id', $teacherId)
            ->whereIn('status', array_map(fn (CandidateStatus $s): string => $s->value, CandidateStatus::registrationStates()))
            ->get()
            ->each(fn (Candidate $candidate) => $this->withdraw($candidate));
    }

    /**
     * Mirror-image of withdrawAllForTeacherVersion(): when a teacher
     * re-accepts obligations after a rejection, every candidate that
     * rejection's cascade withdrew is brought back — reset to Eligible, then
     * immediately re-run through recalculateStatus() so one already fully
     * checklisted before the withdrawal lands straight back on Registered
     * rather than sitting at Eligible until the teacher happens to touch it.
     *
     * @param  list<array{label: string, check: \Closure(Candidate): bool}>  $checklistDefs
     */
    public function reinstateAllForTeacherVersion(int $versionId, int $teacherId, array $checklistDefs): void
    {
        Candidate::where('version_id', $versionId)
            ->where('teacher_id', $teacherId)
            ->where('status', CandidateStatus::TeacherWithdrawn->value)
            ->with(['student.user', 'student.homeAddress', 'student.emergencyContacts'])
            ->get()
            ->each(function (Candidate $candidate) use ($checklistDefs): void {
                $candidate->update(['status' => CandidateStatus::Eligible->value]);
                $this->recalculateStatus($candidate, $checklistDefs);
            });
    }

    /**
     * Recalculate and apply the appropriate auto-promotion status for a
     * candidate based on how many milestone items are complete vs required.
     *
     * eligible  → no milestones done
     * pending   → some but not all milestones done
     * registered → all milestones done
     *
     * Candidates already withdrawn or beyond registration are left unchanged.
     *
     * @param  list<array{label: string, check: \Closure(Candidate): bool}>  $checklistDefs
     */
    public function recalculateStatus(Candidate $candidate, array $checklistDefs): void
    {
        $currentRaw = $candidate->getRawOriginal('status');

        if (! in_array($currentRaw, [
            CandidateStatus::Eligible->value,
            CandidateStatus::Pending->value,
            CandidateStatus::Registered->value,
        ], true)) {
            return;
        }

        $total = count($checklistDefs);
        $done = 0;

        foreach ($checklistDefs as $item) {
            if (($item['check'])($candidate)) {
                $done++;
            }
        }

        $newStatus = match (true) {
            $total === 0 || $done >= $total => CandidateStatus::Registered->value,
            $done > 0 => CandidateStatus::Pending->value,
            default => CandidateStatus::Eligible->value,
        };

        if ($newStatus !== $currentRaw) {
            $candidate->update(['status' => $newStatus]);
        }
    }
}
