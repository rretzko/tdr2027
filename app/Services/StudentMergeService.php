<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Folds a duplicate Student (loser) into the canonical one (winner) and
 * deletes the loser. Shared by the students:merge command and the Founder
 * Merge Students page.
 *
 * candidates.student_id and scores.student_id are cascadeOnDelete, so
 * anything not moved off the loser before $loser->delete() is silently
 * destroyed along with everything hanging off those Candidates (status
 * history, uploads, recordings, payment allocations, audition results).
 */
class StudentMergeService
{
    /** Student columns copied from the loser when the winner's value is null. */
    private const FILLABLE_BLANKS = ['height', 'birthday', 'instrument_id', 'voice_part_id', 'home_school_id'];

    /**
     * Activity a student can perform under their own User, moved to the
     * winner's User before the loser's is deleted. Without this,
     * recordings.uploaded_by cascades (student-uploaded recordings vanish),
     * candidate_status_history.user_id is RESTRICT (the delete throws and
     * the whole merge rolls back), and the others null out their attribution.
     */
    private const USER_ATTRIBUTION_COLUMNS = [
        ['recordings', 'uploaded_by'],
        ['candidate_status_history', 'user_id'],
        ['candidate_upload_files', 'uploaded_by_user_id'],
        ['payment_transactions', 'recorded_by_user_id'],
    ];

    /**
     * Rows hanging off a Candidate that can't be folded into another
     * Candidate in the same Version (unique slots, adjudication data).
     *
     * @var list<string>
     */
    private const UNMOVABLE_CANDIDATE_CHILDREN = ['scores', 'recordings', 'candidate_upload_files', 'audition_results'];

    /**
     * Version names where both students have a Candidate and the loser's
     * carries scores, recordings, uploads or an audition result. Where both
     * have a Candidate but the loser's has none of those, the winner's
     * survives and the loser's payments move onto it (user decision,
     * 2026-10-27); these are the cases that would still lose data, so
     * merge() refuses while any exist.
     *
     * @return list<string>
     */
    public function conflicts(Student $winner, Student $loser): array
    {
        $names = [];

        foreach ($this->sharedVersionCandidates($winner, $loser) as $pair) {
            $hasChildren = collect(self::UNMOVABLE_CANDIDATE_CHILDREN)
                ->contains(fn (string $table): bool => DB::table($table)->where('candidate_id', $pair->loser_candidate_id)->exists());

            if ($hasChildren) {
                $names[] = (string) $pair->version_name;
            }
        }

        return $names;
    }

    /**
     * @return array<int, array{string, string}> step/result log
     *
     * @throws \DomainException when assertMergeable() fails
     */
    public function merge(Student $winner, Student $loser): array
    {
        $this->assertMergeable($winner, $loser);

        return DB::transaction(fn (): array => $this->performMerge($winner, $loser));
    }

    /**
     * @throws \DomainException naming the reason the merge would lose data
     */
    public function assertMergeable(Student $winner, Student $loser): void
    {
        if ($winner->id === $loser->id) {
            throw new \DomainException('Winner and loser must be different students.');
        }

        $conflicts = $this->conflicts($winner, $loser);

        if ($conflicts !== []) {
            throw new \DomainException(
                'Both students are Candidates in '.implode(', ', $conflicts)
                .' and the duplicate already has scores, recordings, uploads or results there. Resolve that Candidate first.'
            );
        }
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

        $transferred = $skipped = 0;

        foreach (DB::table('school_student')->where('student_id', $loser->id)->get() as $row) {
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

        $transferred = $skipped = 0;

        foreach (DB::table('student_teacher')->where('student_id', $loser->id)->get() as $row) {
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
        $count = DB::table('emergency_contacts')->where('student_id', $loser->id)->update(['student_id' => $winner->id]);
        $log[] = ['emergency_contacts reassigned', (string) $count];

        // home_address — HasOne, keep winner's if it already exists
        if ($winner->homeAddress()->exists()) {
            DB::table('home_addresses')->where('student_id', $loser->id)->delete();
            $log[] = ['home_address', 'loser discarded (winner already has one)'];
        } else {
            DB::table('home_addresses')->where('student_id', $loser->id)->update(['student_id' => $winner->id]);
            $log[] = ['home_address', 'transferred'];
        }

        // candidates — unique on (version_id, student_id). Where both have one,
        // the winner's survives and the loser's payment allocations move onto
        // it; conflicts() has already ruled out anything else worth keeping.
        $allocationsMoved = $discarded = 0;

        foreach ($this->sharedVersionCandidates($winner, $loser) as $pair) {
            $allocationsMoved += DB::table('payment_allocations')
                ->where('candidate_id', $pair->loser_candidate_id)
                ->update(['candidate_id' => $pair->winner_candidate_id, 'updated_at' => now()]);

            DB::table('candidates')->where('id', $pair->loser_candidate_id)->delete();
            $discarded++;
        }

        // The rest move whole. They keep their IDs, so child rows (uploads,
        // recordings, history, payments) key on candidate_id and follow.
        $count = DB::table('candidates')->where('student_id', $loser->id)->update(['student_id' => $winner->id, 'updated_at' => now()]);
        $log[] = ['candidates reassigned', (string) $count];
        $log[] = ['candidates discarded (winner has one in that Version)', (string) $discarded];
        $log[] = ['payment_allocations moved to winner candidate', (string) $allocationsMoved];

        // scores — denormalised student_id alongside candidate_id; cascades on student delete
        $count = DB::table('scores')->where('student_id', $loser->id)->update(['student_id' => $winner->id]);
        $log[] = ['scores reassigned', (string) $count];

        // payment_transactions — nullOnDelete would orphan the payer link
        $count = DB::table('payment_transactions')->where('payer_student_id', $loser->id)->update(['payer_student_id' => $winner->id]);
        $log[] = ['payment_transactions reassigned', (string) $count];

        // Student profile — fill the winner's blanks from the loser. Raw
        // values, so the birthday date cast doesn't round-trip through Carbon.
        $winnerRow = DB::table('students')->where('id', $winner->id)->first(self::FILLABLE_BLANKS);
        $loserRow = DB::table('students')->where('id', $loser->id)->first(self::FILLABLE_BLANKS);
        $fills = [];

        foreach (self::FILLABLE_BLANKS as $column) {
            if ($winnerRow?->{$column} === null && $loserRow?->{$column} !== null) {
                $fills[$column] = $loserRow->{$column};
            }
        }

        if ($fills !== []) {
            DB::table('students')->where('id', $winner->id)->update($fills + ['updated_at' => now()]);
        }

        $log[] = ['student fields filled from loser', $fills === [] ? 'none' : implode(', ', array_keys($fills))];

        // Delete loser Student, then its User if it has no Teacher record
        $loserUserId = $loser->user_id;
        $loser->delete();

        $loserUser = User::find($loserUserId);

        if ($loserUser !== null && ! $loserUser->teacher()->exists()) {
            foreach (self::USER_ATTRIBUTION_COLUMNS as [$table, $column]) {
                $count = DB::table($table)->where($column, $loserUserId)->update([$column => $winner->user_id]);
                $log[] = ["{$table}.{$column} reassigned to winner User", (string) $count];
            }

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

    /**
     * @return Collection<int, object{winner_candidate_id: int, loser_candidate_id: int, version_name: string}>
     */
    private function sharedVersionCandidates(Student $winner, Student $loser): Collection
    {
        /** @var Collection<int, object{winner_candidate_id: int, loser_candidate_id: int, version_name: string}> */
        return DB::table('candidates as lc')
            ->join('candidates as wc', fn ($join) => $join
                ->on('wc.version_id', '=', 'lc.version_id')
                ->where('wc.student_id', '=', $winner->id))
            ->join('versions as v', 'v.id', '=', 'lc.version_id')
            ->where('lc.student_id', $loser->id)
            ->get(['wc.id as winner_candidate_id', 'lc.id as loser_candidate_id', 'v.name as version_name']);
    }
}
