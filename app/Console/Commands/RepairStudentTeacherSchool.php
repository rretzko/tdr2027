<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * One-time repair for student_teacher rows whose school_id has no matching
 * school_student row for that student — imported from the legacy system,
 * mostly teachers verified at both a high school and its feeder middle
 * school, with the roster row filed under the wrong one of the two. The
 * roster's Grade column looks up class_of per (student, school), so these
 * rows show "—".
 *
 * school_student is treated as the source of truth: a row is repaired only
 * when exactly one of the student's school_student schools is one where the
 * teacher is active and verified. Anything else is listed for manual review.
 */
class RepairStudentTeacherSchool extends Command
{
    protected $signature = 'students:repair-roster-school
        {--apply : Write the changes (default is a dry run)}
        {--force : Skip the confirmation prompt when applying}';

    protected $description = 'Re-point student_teacher rows at the school where the student is actually enrolled (dry run by default)';

    public function handle(): int
    {
        $mismatched = DB::table('student_teacher as st')
            ->whereNotExists(fn ($query) => $query->from('school_student as ss')
                ->whereColumn('ss.student_id', 'st.student_id')
                ->whereColumn('ss.school_id', 'st.school_id'))
            ->orderBy('st.teacher_id')
            ->orderBy('st.student_id')
            ->get(['st.id', 'st.student_id', 'st.teacher_id', 'st.school_id', 'st.subject']);

        if ($mismatched->isEmpty()) {
            $this->info('No mismatched student_teacher rows found — nothing to do.');

            return self::SUCCESS;
        }

        [$fixable, $review] = $this->classify($mismatched);

        $schoolNames = DB::table('schools')
            ->whereIn('id', $mismatched->pluck('school_id')->merge($fixable->pluck('target_school_id'))->unique())
            ->pluck('name', 'id');

        if ($fixable->isNotEmpty()) {
            $this->info("Repairable ({$fixable->count()}):");
            $this->table(
                ['Row', 'Student', 'Teacher', 'Subject', 'From school', 'To school'],
                $fixable->map(fn (stdClass $row): array => [
                    $row->id,
                    $row->student_id,
                    $row->teacher_id,
                    $row->subject,
                    "{$row->school_id} ".($schoolNames[$row->school_id] ?? '?'),
                    "{$row->target_school_id} ".($schoolNames[$row->target_school_id] ?? '?'),
                ])->all()
            );
        }

        if ($review->isNotEmpty()) {
            $this->warn("Needs manual review ({$review->count()}):");
            $this->table(
                ['Row', 'Student', 'Teacher', 'Subject', 'School', 'Reason'],
                $review->map(fn (stdClass $row): array => [
                    $row->id,
                    $row->student_id,
                    $row->teacher_id,
                    $row->subject,
                    "{$row->school_id} ".($schoolNames[$row->school_id] ?? '?'),
                    $row->reason,
                ])->all()
            );
        }

        if (! $this->option('apply')) {
            $this->info("Dry run — {$fixable->count()} row(s) would be repaired. Re-run with --apply to write.");

            return self::SUCCESS;
        }

        if ($fixable->isEmpty()) {
            $this->info('Nothing repairable.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Repair {$fixable->count()} row(s)?")) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($fixable): void {
            foreach ($fixable as $row) {
                DB::table('student_teacher')
                    ->where('id', $row->id)
                    ->update(['school_id' => $row->target_school_id, 'updated_at' => now()]);
            }
        });

        $this->info("Repaired {$fixable->count()} row(s).");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, stdClass>  $mismatched
     * @return array{0: Collection<int, stdClass>, 1: Collection<int, stdClass>}
     */
    private function classify(Collection $mismatched): array
    {
        $enrollments = DB::table('school_student')
            ->whereIn('student_id', $mismatched->pluck('student_id')->unique())
            ->get(['student_id', 'school_id', 'is_active'])
            ->groupBy('student_id');

        $verifiedSchools = DB::table('school_teacher')
            ->whereIn('teacher_id', $mismatched->pluck('teacher_id')->unique())
            ->where('is_active', true)
            ->whereNotNull('verified_at')
            ->get(['teacher_id', 'school_id'])
            ->groupBy('teacher_id')
            ->map(fn (Collection $rows): array => $rows->pluck('school_id')->all());

        // Unique key is (student_id, teacher_id, school_id, subject) — a move
        // must not land on an existing row, or on another row moved this run.
        $takenKeys = DB::table('student_teacher')
            ->whereIn('student_id', $mismatched->pluck('student_id')->unique())
            ->get(['student_id', 'teacher_id', 'school_id', 'subject'])
            ->map(fn (stdClass $row): string => "{$row->student_id}-{$row->teacher_id}-{$row->school_id}-{$row->subject}")
            ->flip()
            ->all();

        $fixable = collect();
        $review = collect();

        foreach ($mismatched as $row) {
            $studentEnrollments = $enrollments->get($row->student_id, collect());

            if ($studentEnrollments->isEmpty()) {
                $review->push((object) [...(array) $row, 'reason' => 'Student has no school_student row at any school']);

                continue;
            }

            $candidates = $studentEnrollments->filter(
                fn (stdClass $enrollment): bool => in_array($enrollment->school_id, $verifiedSchools->get($row->teacher_id, []), false)
            );

            if ($candidates->count() > 1) {
                $candidates = $candidates->filter(fn (stdClass $enrollment): bool => (bool) $enrollment->is_active);
            }

            if ($candidates->isEmpty()) {
                $review->push((object) [...(array) $row, 'reason' => 'Teacher is not verified at the student\'s school ('.$studentEnrollments->pluck('school_id')->implode(', ').')']);

                continue;
            }

            if ($candidates->count() > 1) {
                $review->push((object) [...(array) $row, 'reason' => 'Ambiguous — teacher is verified at several of the student\'s schools']);

                continue;
            }

            $targetSchoolId = (int) $candidates->first()->school_id;
            $key = "{$row->student_id}-{$row->teacher_id}-{$targetSchoolId}-{$row->subject}";

            if (isset($takenKeys[$key])) {
                $review->push((object) [...(array) $row, 'reason' => "Duplicate — a {$row->subject} row already exists at school {$targetSchoolId}"]);

                continue;
            }

            $takenKeys[$key] = true;
            $fixable->push((object) [...(array) $row, 'target_school_id' => $targetSchoolId]);
        }

        return [$fixable, $review];
    }
}
