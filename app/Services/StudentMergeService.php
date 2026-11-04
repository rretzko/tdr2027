<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateStatus;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\PhoneNormalizer;
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

            $plan = $this->phonePlan($winner->user_id, $loserUserId);

            // users.cell_phone is unique, so clear it on the loser before the winner takes it.
            if ($plan['account_cell'] !== null) {
                DB::table('users')->where('id', $loserUserId)->update(['cell_phone' => null]);
                DB::table('users')->where('id', $winner->user_id)->update(['cell_phone' => $plan['account_cell'], 'updated_at' => now()]);
            }

            $log[] = ['users.cell_phone moved to winner User', $plan['account_cell'] !== null ? 'yes' : 'no'];

            $moved = $plan['phone_ids_to_move'] === []
                ? 0
                : DB::table('phones')->whereIn('id', $plan['phone_ids_to_move'])->update(['user_id' => $winner->user_id, 'updated_at' => now()]);
            $log[] = ['phones moved to winner User', (string) $moved];
            $log[] = ['phone numbers discarded (winner has a different one)', $plan['discarded'] === [] ? 'none' : implode(', ', $plan['discarded'])];

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
     * The two students' default voice part names when both are set and
     * differ. The merge keeps the winner's, so the loser's is lost (the
     * winner's is only filled from the loser when it's empty).
     *
     * @return array{winner: string, loser: string}|null
     */
    public function voicePartDifference(Student $winner, Student $loser): ?array
    {
        $parts = DB::table('students as s')
            ->join('voice_parts as vp', 'vp.id', '=', 's.voice_part_id')
            ->whereIn('s.id', [$winner->id, $loser->id])
            ->pluck('vp.name', 's.id');

        $winnerPart = $parts->get($winner->id);
        $loserPart = $parts->get($loser->id);

        if ($winnerPart === null || $loserPart === null || $winnerPart === $loserPart) {
            return null;
        }

        return ['winner' => (string) $winnerPart, 'loser' => (string) $loserPart];
    }

    /**
     * Versions where both students are Candidates and the two Candidates
     * differ in voice part or status. The merge keeps the winner's
     * Candidate and deletes the loser's, so the loser's values are lost;
     * the founder is warned and fixes the Candidate afterwards if needed.
     *
     * @return list<array{version_name: string, winner_voice_part: string, loser_voice_part: string, winner_status: string, loser_status: string}>
     */
    public function sharedCandidateDifferences(Student $winner, Student $loser): array
    {
        $rows = DB::table('candidates as lc')
            ->join('candidates as wc', fn ($join) => $join
                ->on('wc.version_id', '=', 'lc.version_id')
                ->where('wc.student_id', '=', $winner->id))
            ->join('versions as v', 'v.id', '=', 'lc.version_id')
            ->join('voice_parts as lvp', 'lvp.id', '=', 'lc.voice_part_id')
            ->join('voice_parts as wvp', 'wvp.id', '=', 'wc.voice_part_id')
            ->where('lc.student_id', $loser->id)
            ->orderBy('v.name')
            ->get([
                'v.name as version_name',
                'wvp.name as winner_voice_part',
                'lvp.name as loser_voice_part',
                'wc.status as winner_status',
                'lc.status as loser_status',
            ]);

        $differences = [];

        foreach ($rows as $row) {
            if ($row->winner_voice_part === $row->loser_voice_part && $row->winner_status === $row->loser_status) {
                continue;
            }

            $differences[] = [
                'version_name' => (string) $row->version_name,
                'winner_voice_part' => (string) $row->winner_voice_part,
                'loser_voice_part' => (string) $row->loser_voice_part,
                'winner_status' => CandidateStatus::tryFrom((string) $row->winner_status)?->label() ?? (string) $row->winner_status,
                'loser_status' => CandidateStatus::tryFrom((string) $row->loser_status)?->label() ?? (string) $row->loser_status,
            ];
        }

        return $differences;
    }

    /**
     * Formatted phone numbers the merge would delete: the loser has them in a
     * slot (users.cell_phone, or a phones row of a given type) where the
     * winner already holds a different number, and the winner has no copy
     * of the number anywhere. Empty when the loser's User survives the merge
     * (it has a Teacher record), since its phones then stay with it.
     *
     * @return list<string>
     */
    public function discardedPhones(Student $winner, Student $loser): array
    {
        if (Teacher::where('user_id', $loser->user_id)->exists()) {
            return [];
        }

        return array_map(
            fn (string $number): string => (string) PhoneNormalizer::format($number),
            $this->phonePlan($winner->user_id, $loser->user_id)['discarded'],
        );
    }

    /**
     * Student cell numbers live in two places: users.cell_phone (set by
     * teachers on the roster and by event reports) and phones rows (set at
     * SFDI self-registration). The loser's number in each slot moves to the
     * winner when the winner's slot is empty; otherwise the winner's wins.
     *
     * @return array{account_cell: ?string, phone_ids_to_move: list<int>, discarded: list<string>}
     */
    private function phonePlan(int $winnerUserId, int $loserUserId): array
    {
        $winnerCell = DB::table('users')->where('id', $winnerUserId)->value('cell_phone');
        $loserCell = DB::table('users')->where('id', $loserUserId)->value('cell_phone');
        $winnerPhones = DB::table('phones')->where('user_id', $winnerUserId)->get(['type', 'raw_number']);
        $loserPhones = DB::table('phones')->where('user_id', $loserUserId)->orderBy('id')->get(['id', 'type', 'raw_number']);

        $winnerNumbers = $winnerPhones->pluck('raw_number')->push($winnerCell)->filter()->map(fn ($n): string => (string) $n)->all();
        $winnerTypes = $winnerPhones->pluck('type')->map(fn ($t): string => (string) $t)->all();

        $accountCell = null;
        $toMove = [];
        $discarded = [];

        if ($loserCell !== null && $loserCell !== '') {
            if ($winnerCell === null || $winnerCell === '') {
                $accountCell = (string) $loserCell;
            } elseif (! in_array((string) $loserCell, $winnerNumbers, true)) {
                $discarded[] = (string) $loserCell;
            }
        }

        foreach ($loserPhones as $phone) {
            if (! in_array((string) $phone->type, $winnerTypes, true)) {
                $toMove[] = (int) $phone->id;
            } elseif (! in_array((string) $phone->raw_number, $winnerNumbers, true)) {
                $discarded[] = (string) $phone->raw_number;
            }
        }

        return [
            'account_cell' => $accountCell,
            'phone_ids_to_move' => $toMove,
            'discarded' => array_values(array_unique($discarded)),
        ];
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
