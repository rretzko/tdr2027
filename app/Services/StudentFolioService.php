<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EventStatus;
use App\Enums\VersionInvitationStatus;
use App\Models\Candidate;
use App\Models\Pivots\SchoolStudent;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\StudentTeacher;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionInvitation;
use Illuminate\Support\Collection;

/**
 * Founder-side "why can't this student see X?" diagnostics (Founder\StudentFolio).
 * Each check mirrors one gate of the real code path so a failing row points at
 * the exact cause:
 * - Roster visibility mirrors Students\Index::rosterQuery() — a student_teacher
 *   row is only listed while the teacher's own school_teacher row at that
 *   school is active AND verified.
 * - Version checks mirror EligibilityService::eligibleStudents() plus
 *   AutoEnrollmentService::enrollOne()'s voice-part gate; the student's SFDI
 *   "My Events" page lists only existing Candidate rows, so a missing
 *   Candidate is the whole "can't see the event" story.
 *
 * Read-only: nothing here creates or changes rows.
 *
 * @phpstan-type Check array{label: string, passed: bool, detail: string}
 * @phpstan-type RosterRow array{link: StudentTeacher, visible: bool, reason: string}
 * @phpstan-type TeacherDiagnostic array{teacher: Teacher, checks: list<Check>, eligible: bool}
 * @phpstan-type VersionDiagnostic array{version: Version, candidate: ?Candidate, teachers: list<TeacherDiagnostic>}
 */
class StudentFolioService
{
    /**
     * One row per student_teacher link (active or not), with whether that
     * teacher would see it on their Students roster.
     *
     * @return list<RosterRow>
     */
    public function rosterVisibility(Student $student): array
    {
        $links = StudentTeacher::where('student_id', $student->id)
            ->with(['teacher.user', 'school'])
            ->orderBy('teacher_id')
            ->get();

        $rows = [];

        foreach ($links as $link) {
            $schoolTeacher = SchoolTeacher::where('teacher_id', $link->teacher_id)
                ->where('school_id', $link->school_id)
                ->first();

            [$visible, $reason] = match (true) {
                $schoolTeacher === null => [false, 'Teacher has no school_teacher row at this school.'],
                ! $schoolTeacher->is_active => [false, 'Teacher is inactive at this school (deactivated on their Schools page).'],
                $schoolTeacher->verified_at === null => [false, 'Teacher is not yet verified at this school.'],
                $link->isPending() => [true, 'Listed as Pending — claim awaiting approval from the student\'s existing teacher.'],
                ! $link->is_active => [true, 'Listed as Inactive.'],
                default => [true, 'Listed as Active.'],
            };

            $rows[] = ['link' => $link, 'visible' => $visible, 'reason' => $reason];
        }

        return $rows;
    }

    /**
     * One entry per currently Active Version, with this student's Candidate
     * (if any) and a per-teacher eligibility checklist for every teacher the
     * student has a student_teacher link with.
     *
     * @return list<VersionDiagnostic>
     */
    public function versionDiagnostics(Student $student): array
    {
        $teachers = Teacher::whereIn('id', StudentTeacher::where('student_id', $student->id)->select('teacher_id'))
            ->with('user')
            ->get();

        $versions = Version::where('status', EventStatus::Active->value)
            ->with(['event.grades', 'classOfs'])
            ->get()
            ->sortBy(fn (Version $version): string => $version->event->name.' '.$version->name)
            ->values();

        $candidates = Candidate::where('student_id', $student->id)
            ->whereIn('version_id', $versions->pluck('id'))
            ->with(['teacher.user', 'school'])
            ->get()
            ->keyBy('version_id');

        $diagnostics = [];

        foreach ($versions as $version) {
            $teacherDiagnostics = [];

            foreach ($teachers as $teacher) {
                $checks = $this->checks($version, $student, $teacher);

                $teacherDiagnostics[] = [
                    'teacher' => $teacher,
                    'checks' => $checks,
                    'eligible' => collect($checks)->every(fn (array $check): bool => $check['passed']),
                ];
            }

            $diagnostics[] = [
                'version' => $version,
                'candidate' => $candidates->get($version->id),
                'teachers' => $teacherDiagnostics,
            ];
        }

        return $diagnostics;
    }

    /**
     * @return list<Check>
     */
    private function checks(Version $version, Student $student, Teacher $teacher): array
    {
        $invitation = VersionInvitation::where('version_id', $version->id)
            ->where('teacher_id', $teacher->id)
            ->first();

        $teacherSchoolRows = SchoolTeacher::where('teacher_id', $teacher->id)->get()->keyBy('school_id');

        $teacherVerifiedSchoolIds = $teacherSchoolRows
            ->filter(fn (SchoolTeacher $row): bool => $row->is_active && $row->verified_at !== null)
            ->keys();

        // Single-active-school invariant (SchoolStudentObserver), so the first
        // active row is the student's current school.
        $studentActiveSchoolRows = SchoolStudent::where('student_id', $student->id)->where('is_active', true)->get();

        $sharedSchoolNames = School::whereIn('id', $studentActiveSchoolRows->pluck('school_id')->intersect($teacherVerifiedSchoolIds))
            ->orderBy('name')
            ->pluck('name');

        $links = StudentTeacher::where('student_id', $student->id)->where('teacher_id', $teacher->id)->get();

        $grade = $student->grade;
        $eventGrades = $version->event->grades->pluck('grade')->map(fn ($g): int => (int) $g);
        $classOfs = $version->classOfs->pluck('class_of')->map(fn ($c): int => (int) $c);
        $classOf = $studentActiveSchoolRows->first()?->class_of;
        $classOf = $classOf !== null ? (int) $classOf : null;

        $voiceParts = $version->availableVoicePartsForGrade($grade);

        return [
            [
                'label' => 'Teacher invited to this version',
                'passed' => $invitation !== null,
                'detail' => $invitation !== null
                    ? 'Invitation status: '.VersionInvitationStatus::from($invitation->getRawOriginal('status'))->label().'.'
                    : 'No version_invitations row for this teacher.',
            ],
            [
                'label' => 'Obligations not rejected',
                'passed' => $invitation === null || $invitation->getRawOriginal('status') !== VersionInvitationStatus::Rejected->value,
                'detail' => $invitation?->getRawOriginal('status') === VersionInvitationStatus::Rejected->value
                    ? 'Teacher rejected the obligations; nothing can be enrolled until they accept.'
                    : 'OK.',
            ],
            [
                'label' => 'Shared school (student active, teacher active + verified)',
                'passed' => $sharedSchoolNames->isNotEmpty(),
                'detail' => $sharedSchoolNames->isNotEmpty()
                    ? $sharedSchoolNames->join(', ')
                    : $this->sharedSchoolFailureDetail($studentActiveSchoolRows->first(), $teacherSchoolRows),
            ],
            [
                'label' => 'Active student-teacher link',
                'passed' => $links->contains(fn (StudentTeacher $link): bool => $link->is_active),
                'detail' => match (true) {
                    $links->contains(fn (StudentTeacher $link): bool => $link->is_active) => 'OK.',
                    $links->contains(fn (StudentTeacher $link): bool => $link->isPending()) => 'Link is pending claim approval.',
                    default => 'All links to this teacher are inactive.',
                },
            ],
            [
                'label' => 'Grade allowed by event',
                'passed' => $eventGrades->isEmpty() || $eventGrades->contains($grade),
                'detail' => $eventGrades->isEmpty()
                    ? 'Event has no grade restriction.'
                    : 'Student grade '.($grade ?? 'unknown').'; event allows '.$eventGrades->sort()->join(', ').'.',
            ],
            [
                'label' => 'Class of allowed by version',
                'passed' => $classOfs->isEmpty() || ($classOf !== null && $classOfs->contains($classOf)),
                'detail' => $classOfs->isEmpty()
                    ? 'Version has no class-of restriction.'
                    : 'Student class of '.($classOf ?? 'unknown').'; version allows '.$classOfs->sort()->join(', ').'.',
            ],
            [
                'label' => 'Grade-eligible voice part configured',
                'passed' => $voiceParts->isNotEmpty(),
                'detail' => $voiceParts->isNotEmpty()
                    ? $voiceParts->count().' available.'
                    : 'No ensemble voice part admits grade '.($grade ?? 'unknown').' — auto-enrollment skips the student.',
            ],
        ];
    }

    /**
     * @param  Collection<int, SchoolTeacher>  $teacherSchoolRows  keyed by school_id
     */
    private function sharedSchoolFailureDetail(?SchoolStudent $studentSchool, Collection $teacherSchoolRows): string
    {
        if ($studentSchool === null) {
            return 'Student has no active school.';
        }

        $schoolName = School::whereKey($studentSchool->school_id)->value('name');
        $teacherRow = $teacherSchoolRows->get($studentSchool->school_id);

        return match (true) {
            $teacherRow === null => "Teacher is not attached to the student's school ({$schoolName}).",
            ! $teacherRow->is_active => "Teacher is inactive at {$schoolName}.",
            default => "Teacher is not verified at {$schoolName}.",
        };
    }
}
