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
        return Candidate::create([
            'student_id' => $student->id,
            'version_id' => $version->id,
            'school_id' => $schoolId,
            'teacher_id' => $teacher->id,
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
     * Recalculate and apply the appropriate auto-promotion status for a
     * candidate based on how many milestone items are complete vs required.
     *
     * eligible  → no milestones done
     * pending   → some but not all milestones done
     * registered → all milestones done
     *
     * Candidates already withdrawn or beyond registration are left unchanged.
     *
     * @param list<array{label: string, check: \Closure(Candidate): bool}> $checklistDefs
     */
    public function recalculateStatus(Candidate $candidate, array $checklistDefs): void
    {
        $currentRaw = $candidate->getRawOriginal('status');

        if (!in_array($currentRaw, [
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
