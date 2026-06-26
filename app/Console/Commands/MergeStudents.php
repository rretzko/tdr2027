<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Student;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MergeStudents extends Command
{
    protected $signature = 'students:merge
        {winner : Student ID to keep}
        {loser  : Student ID to delete}
        {--force : Skip confirmation prompt}';

    protected $description = 'Merge a duplicate student (loser) into another (winner) and permanently delete the loser';

    public function handle(): int
    {
        $winner = Student::with('user')->find($this->argument('winner'));
        $loser  = Student::with('user')->find($this->argument('loser'));

        if ($winner === null || $loser === null) {
            $this->error('One or both Student IDs not found.');

            return self::FAILURE;
        }

        if ($winner->id === $loser->id) {
            $this->error('Winner and loser must be different students.');

            return self::FAILURE;
        }

        $this->displaySummary($winner, $loser);

        if (! $this->option('force') && ! $this->confirm("Merge loser #{$loser->id} into winner #{$winner->id} and permanently delete the loser?")) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $log = DB::transaction(fn (): array => $this->performMerge($winner, $loser));

        $this->info('Merge complete.');
        $this->table(['Step', 'Result'], $log);

        return self::SUCCESS;
    }

    private function displaySummary(Student $winner, Student $loser): void
    {
        $this->table(
            ['Role', 'Student ID', 'Name', 'Email', 'Birthday'],
            [
                [
                    'KEEP (winner)',
                    $winner->id,
                    $winner->user->first_name.' '.$winner->user->last_name,
                    $winner->user->email,
                    $winner->birthday?->format('Y-m-d') ?? '—',
                ],
                [
                    'DELETE (loser)',
                    $loser->id,
                    $loser->user->first_name.' '.$loser->user->last_name,
                    $loser->user->email,
                    $loser->birthday?->format('Y-m-d') ?? '—',
                ],
            ]
        );
    }

    /**
     * @return array<int, array{string, string}>
     */
    private function performMerge(Student $winner, Student $loser): array
    {
        $log = [];

        // school_student — unique on (student_id, school_id)
        $winnerSchoolIds = DB::table('school_student')
            ->where('student_id', $winner->id)
            ->pluck('school_id')
            ->all();

        $loserSchoolRows = DB::table('school_student')
            ->where('student_id', $loser->id)
            ->get();

        $transferred = $skipped = 0;

        foreach ($loserSchoolRows as $row) {
            if (in_array($row->school_id, $winnerSchoolIds, true)) {
                DB::table('school_student')->where('id', $row->id)->delete();
                $skipped++;
            } else {
                DB::table('school_student')->where('id', $row->id)->update(['student_id' => $winner->id]);
                $transferred++;
            }
        }

        $log[] = ['school_student transferred', (string) $transferred];
        $log[] = ['school_student skipped (conflict)', (string) $skipped];

        // student_teacher — unique on (student_id, teacher_id, school_id, subject)
        $winnerKeys = DB::table('student_teacher')
            ->where('student_id', $winner->id)
            ->get(['teacher_id', 'school_id', 'subject'])
            ->map(fn (object $r): string => "{$r->teacher_id}:{$r->school_id}:{$r->subject}")
            ->all();

        $loserTeacherRows = DB::table('student_teacher')
            ->where('student_id', $loser->id)
            ->get();

        $transferred = $skipped = 0;

        foreach ($loserTeacherRows as $row) {
            if (in_array("{$row->teacher_id}:{$row->school_id}:{$row->subject}", $winnerKeys, true)) {
                DB::table('student_teacher')->where('id', $row->id)->delete();
                $skipped++;
            } else {
                DB::table('student_teacher')->where('id', $row->id)->update(['student_id' => $winner->id]);
                $transferred++;
            }
        }

        $log[] = ['student_teacher transferred', (string) $transferred];
        $log[] = ['student_teacher skipped (duplicate)', (string) $skipped];

        // emergency_contacts — bypass soft-delete scope so deleted rows are also reassigned
        $contactCount = DB::table('emergency_contacts')
            ->where('student_id', $loser->id)
            ->update(['student_id' => $winner->id]);

        $log[] = ['emergency_contacts reassigned', (string) $contactCount];

        // home_address — HasOne, keep winner's if it already exists
        if ($winner->homeAddress()->exists()) {
            DB::table('home_addresses')->where('student_id', $loser->id)->delete();
            $log[] = ['home_address', 'loser discarded (winner already has one)'];
        } else {
            DB::table('home_addresses')
                ->where('student_id', $loser->id)
                ->update(['student_id' => $winner->id]);
            $log[] = ['home_address', 'transferred'];
        }

        // Delete loser Student, then its User if it has no Teacher record
        $loserUserId = $loser->user_id;
        $loser->delete();

        $loserUser = User::find($loserUserId);

        if ($loserUser !== null && ! $loserUser->teacher()->exists()) {
            $loserUser->pageVisits()->delete();
            $loserUser->phones()->delete();
            $loserUser->socialAccounts()->delete();
            $loserUser->delete();
            $log[] = ['loser User deleted', "ID {$loserUserId}"];
        } else {
            $log[] = ['loser User', 'kept (has Teacher record or not found)'];
        }

        return $log;
    }
}
