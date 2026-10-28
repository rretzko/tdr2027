<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Candidate;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\StudentTeacher;
use App\Models\Teacher;
use App\Models\VersionInvitation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moves a replaced teacher's current students at a school over to the teacher
 * who replaced them. Triggered by SchoolTeacherObserver once a replacement link
 * is active and verified, whichever verification path got it there — see
 * docs/plans/replaced-teacher-transfer-fix.md for why it no longer hangs off
 * individual verification call sites.
 */
final class ReplacedTeacherStudentTransfer
{
    private const array HONORIFICS = ['mr', 'mrs', 'ms', 'miss', 'mx', 'dr'];

    /**
     * Moves the replaced teacher's current students at this school over to the
     * newly-verified teacher. "Current" means still actively enrolled there with
     * a class_of at or after the school's senior year, so alumni and students
     * who've moved on to another school aren't disturbed.
     *
     * If the new teacher already has a student_teacher row for the same student/
     * subject (e.g. they already co-taught), the replaced teacher's row is
     * dropped instead of moved, since moving it would violate the table's unique
     * (student_id, teacher_id, school_id, subject) constraint.
     *
     * Candidate rows at this school for the transferred students follow the
     * roster, except where moving one would disrupt registration: a Version the
     * new teacher holds no invitation for (they couldn't see it), or a candidate
     * with a teacher-paid allocation (the payer's ledger would no longer match).
     * Those stay with the replaced teacher and are logged.
     *
     * Stamps replacement_transferred_at either way, so it isn't re-run on every
     * later save (e.g. the annual re-verification).
     *
     * @return int<0, max> number of students transferred
     */
    public static function transfer(SchoolTeacher $schoolTeacher): int
    {
        $replacedTeacher = self::resolveReplacedTeacher($schoolTeacher);

        if ($replacedTeacher === null) {
            return 0;
        }

        $transferredCount = DB::transaction(fn (): int => self::moveStudents($schoolTeacher, $replacedTeacher));

        $schoolTeacher->forceFill(['replacement_transferred_at' => now()])->saveQuietly();

        return $transferredCount;
    }

    /**
     * The teacher this link replaces: replaced_teacher_id when set (picked from a
     * list), otherwise a case/whitespace/honorific-insensitive match of the legacy
     * free-typed replacing_teacher_name against the school's other teachers.
     * Ambiguous or unmatched names resolve to null rather than guessing.
     */
    public static function resolveReplacedTeacher(SchoolTeacher $schoolTeacher): ?Teacher
    {
        $schoolTeachers = Teacher::query()
            ->whereHas('schools', fn ($query) => $query->where('schools.id', $schoolTeacher->school_id))
            ->where('teachers.id', '!=', $schoolTeacher->teacher_id)
            ->with('user');

        if ($schoolTeacher->replaced_teacher_id !== null) {
            return $schoolTeachers->where('teachers.id', $schoolTeacher->replaced_teacher_id)->first();
        }

        if (blank($schoolTeacher->replacing_teacher_name)) {
            return null;
        }

        $wanted = self::normalizeName($schoolTeacher->replacing_teacher_name);

        $matches = $schoolTeachers->get()->filter(fn (Teacher $teacher): bool => in_array($wanted, [
            self::normalizeName((string) $teacher->user->name),
            self::normalizeName($teacher->user->first_name.' '.$teacher->user->last_name),
        ], true));

        if ($matches->count() !== 1) {
            Log::warning('ReplacedTeacherStudentTransfer: replaced teacher not resolved', [
                'school_teacher_id' => $schoolTeacher->id,
                'replacing_teacher_name' => $schoolTeacher->replacing_teacher_name,
                'match_count' => $matches->count(),
            ]);

            return null;
        }

        return $matches->first();
    }

    /**
     * The school's other teachers (active or not — a departing teacher's link isn't
     * necessarily removed when they leave), keyed by teacher id, for the
     * "teacher you're replacing" pickers.
     *
     * @return Collection<int, string>
     */
    public static function replaceableTeacherOptions(int $schoolId, int $excludeTeacherId): Collection
    {
        return Teacher::query()
            ->whereHas('schools', fn ($query) => $query->where('schools.id', $schoolId))
            ->where('teachers.id', '!=', $excludeTeacherId)
            ->with('user')
            ->get()
            ->filter(fn (Teacher $teacher): bool => filled($teacher->user->name))
            ->mapWithKeys(fn (Teacher $teacher): array => [$teacher->id => (string) $teacher->user->name])
            ->sort();
    }

    public static function normalizeName(string $name): string
    {
        $words = preg_split('/\s+/', strtolower(trim($name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words !== [] && in_array(rtrim($words[0], '.'), self::HONORIFICS, true)) {
            array_shift($words);
        }

        return implode(' ', $words);
    }

    private static function moveStudents(SchoolTeacher $schoolTeacher, Teacher $replacedTeacher): int
    {
        $school = $schoolTeacher->school;
        $newTeacherId = $schoolTeacher->teacher_id;

        $currentStudentIds = $school->students()
            ->wherePivot('is_active', true)
            ->where('school_student.class_of', '>=', $school->senior_year)
            ->pluck('students.id');

        $rows = StudentTeacher::where('school_id', $school->id)
            ->where('teacher_id', $replacedTeacher->id)
            ->whereIn('student_id', $currentStudentIds)
            ->get();

        $transferredStudentIds = [];

        foreach ($rows as $row) {
            $newTeacherAlreadyHasRow = StudentTeacher::where('student_id', $row->student_id)
                ->where('teacher_id', $newTeacherId)
                ->where('school_id', $row->school_id)
                ->where('subject', $row->getRawOriginal('subject'))
                ->exists();

            if ($newTeacherAlreadyHasRow) {
                $row->delete();

                continue;
            }

            $row->update(['teacher_id' => $newTeacherId]);
            $transferredStudentIds[$row->student_id] = true;
        }

        if ($transferredStudentIds === []) {
            return 0;
        }

        $candidates = Candidate::where('school_id', $school->id)
            ->where('teacher_id', $replacedTeacher->id)
            ->whereIn('student_id', array_keys($transferredStudentIds))
            ->get(['id', 'version_id']);

        $invitedVersionIds = VersionInvitation::where('teacher_id', $newTeacherId)
            ->whereIn('version_id', $candidates->pluck('version_id')->unique())
            ->pluck('version_id')
            ->all();

        $teacherPaidCandidateIds = DB::table('payment_allocations')
            ->whereIn('candidate_id', $candidates->pluck('id'))
            ->whereExists(fn (QueryBuilder $query) => $query->selectRaw('1')
                ->from('payment_transactions')
                ->whereColumn('payment_transactions.id', 'payment_allocations.payment_transaction_id')
                ->whereNotNull('payment_transactions.payer_teacher_id'))
            ->pluck('candidate_id')
            ->all();

        [$movable, $kept] = $candidates->partition(fn (Candidate $candidate): bool => in_array($candidate->version_id, $invitedVersionIds, true)
            && ! in_array($candidate->id, $teacherPaidCandidateIds, true));

        if ($movable->isNotEmpty()) {
            Candidate::whereIn('id', $movable->pluck('id'))->update(['teacher_id' => $newTeacherId]);
        }

        if ($kept->isNotEmpty()) {
            Log::warning('ReplacedTeacherStudentTransfer: candidates left with the replaced teacher', [
                'school_teacher_id' => $schoolTeacher->id,
                'replaced_teacher_id' => $replacedTeacher->id,
                'candidate_ids' => $kept->pluck('id')->all(),
            ]);
        }

        return count($transferredStudentIds);
    }
}
