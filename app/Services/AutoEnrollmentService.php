<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EventStatus;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionInvitation;

/**
 * Proactively enrolls a teacher's eligible students as Candidates, rather
 * than waiting for the teacher to manually enroll each one. Triggers (see
 * VersionInvitationObserver, StudentTeacherObserver, VersionObserver, and
 * VersionRoleAssignmentService):
 * - A teacher is newly invited to a Version → enroll every student of
 *   theirs already eligible for it (skipped while the Version is still
 *   Sandbox — see VersionInvitationObserver).
 * - A student is newly added/reactivated on a teacher's roster → enroll
 *   that student into every Version the teacher currently holds an
 *   invitation for, for which registration is currently active.
 * - A Version transitions into Active, or a version-scoped role is granted
 *   → enrollAllInvitedTeachersForVersion() backfills every already-invited
 *   teacher, so invitations created while the Version was still Sandbox
 *   (e.g. cloned invitations) aren't stuck forever.
 *
 * All funnel through the same EligibilityService::eligibleStudents() pool
 * used everywhere else (invitation status, shared active school, grade
 * match, not already a candidate) — this doesn't define a separate notion
 * of "eligible."
 */
class AutoEnrollmentService
{
    public function __construct(
        private readonly EligibilityService $eligibility,
        private readonly CandidateService $candidates,
    ) {}

    /**
     * Enrolls every currently-eligible student of this teacher into this
     * Version. Triggered when the teacher is newly invited (§5.4/§5.8).
     */
    public function enrollEligibleStudentsForVersion(Version $version, Teacher $teacher): void
    {
        foreach ($this->eligibility->eligibleStudents($version, $teacher) as $student) {
            $this->enrollOne($version, $student, $teacher);
        }
    }

    /**
     * Runs enrollEligibleStudentsForVersion() for every teacher already
     * holding a VersionInvitation on this Version, regardless of the
     * Version's own status. Used to backfill invitations that were created
     * while the Version wasn't yet Active — cloned invitations
     * (VersionCloningService always clones into a Sandbox Version) and any
     * other invitation issued before go-live — for two triggers:
     * - VersionObserver::updated(), when the Version transitions into
     *   Active, so ordinary participants aren't stuck waiting forever.
     * - VersionRoleAssignmentService, when a version-scoped role is granted,
     *   so an Event Manager/Registration Manager/etc. can review real
     *   candidate data on the Candidate Management pages before the Version
     *   goes Active.
     * Safe to call repeatedly: eligibleStudents() already excludes students
     * who are already a Candidate for the Version.
     */
    public function enrollAllInvitedTeachersForVersion(Version $version): void
    {
        VersionInvitation::where('version_id', $version->id)
            ->get()
            ->each(function (VersionInvitation $invitation) use ($version): void {
                $this->enrollEligibleStudentsForVersion($version, $invitation->teacher);
            });
    }

    /**
     * Enrolls this newly-rostered student into every Version that is
     * currently Active and for which the teacher already holds an
     * invitation (any status — an existing row is what matters here, not
     * which stage of it they're in).
     */
    public function enrollNewStudentIntoInvitedActiveVersions(Student $student, Teacher $teacher): void
    {
        $invitedVersionIds = VersionInvitation::where('teacher_id', $teacher->id)->pluck('version_id');

        if ($invitedVersionIds->isEmpty()) {
            return;
        }

        $versions = Version::whereIn('id', $invitedVersionIds)
            ->where('status', EventStatus::Active->value)
            ->get();

        foreach ($versions as $version) {
            $isEligible = $this->eligibility->eligibleStudents($version, $teacher)
                ->contains(fn (Student $eligibleStudent): bool => $eligibleStudent->id === $student->id);

            if ($isEligible) {
                $this->enrollOne($version, $student, $teacher);
            }
        }
    }

    private function enrollOne(Version $version, Student $student, Teacher $teacher): void
    {
        $schoolId = $this->eligibility->resolveSchool($student, $teacher);

        if ($schoolId === null) {
            return;
        }

        $voicePartId = $this->resolveVoicePartId($version, $student);

        if ($voicePartId === null) {
            return;
        }

        $this->candidates->enroll($version, $student, $teacher, $schoolId, $voicePartId);
    }

    /**
     * The student's own voice_part_id if it's one of the Version's
     * available (ensemble-linked) voice parts; otherwise the first
     * available voice part, so the NOT NULL column is always satisfiable.
     * Returns null only if the Event has no ensemble voice parts configured
     * at all yet — nothing sensible to default to, so the enrollment is
     * skipped rather than guessed.
     */
    private function resolveVoicePartId(Version $version, Student $student): ?int
    {
        $available = $version->availableVoiceParts();

        if ($available->isEmpty()) {
            return null;
        }

        if ($student->voice_part_id !== null && $available->contains('id', $student->voice_part_id)) {
            return $student->voice_part_id;
        }

        return $available->first()->id;
    }
}
