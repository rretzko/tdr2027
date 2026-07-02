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
}
