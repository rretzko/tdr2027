<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ScoreSeeder extends Seeder
{
    /**
     * @var array<int, true>
     */
    private array $existingVersionIds = [];

    /**
     * @var array<int, true>
     */
    private array $existingCandidateIds = [];

    /**
     * @var array<int, true>
     */
    private array $existingStudentIds = [];

    /**
     * @var array<int, true>
     */
    private array $existingSchoolIds = [];

    /**
     * @var array<int, true>
     */
    private array $existingScoreCategoryIds = [];

    /**
     * @var array<int, true>
     */
    private array $existingScoreFactorIds = [];

    /**
     * @var array<int, true>
     */
    private array $existingRoomJudgeIds = [];

    /**
     * @var array<int, true>
     */
    private array $existingVoicePartIds = [];

    private int $skippedCount = 0;

    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/scores.csv)
     * that is gitignored and not pushed to the repository. Skips silently
     * when the file is absent so other environments are unaffected.
     *
     * `judge_id` here is a room_judges.id (Score::judge() belongs to
     * RoomJudge, not User) — validated against room_judges, not users.
     *
     * Rows referencing a version/candidate/student/school/score_category/
     * score_factor/room_judge/voice_part not present in the currently-seeded
     * reference tables are skipped rather than failing the whole import,
     * matching CandidateSeeder's/RecordingSeeder's/AuditionResultSeeder's
     * convention.
     */
    public function run(): void
    {
        $this->existingVersionIds = array_fill_keys(DB::table('versions')->pluck('id')->all(), true);
        $this->existingCandidateIds = array_fill_keys(DB::table('candidates')->pluck('id')->all(), true);
        $this->existingStudentIds = array_fill_keys(DB::table('students')->pluck('id')->all(), true);
        $this->existingSchoolIds = array_fill_keys(DB::table('schools')->pluck('id')->all(), true);
        $this->existingScoreCategoryIds = array_fill_keys(DB::table('score_categories')->pluck('id')->all(), true);
        $this->existingScoreFactorIds = array_fill_keys(DB::table('score_factors')->pluck('id')->all(), true);
        $this->existingRoomJudgeIds = array_fill_keys(DB::table('room_judges')->pluck('id')->all(), true);
        $this->existingVoicePartIds = array_fill_keys(DB::table('voice_parts')->pluck('id')->all(), true);

        $rows = $this->readCsv('scores.csv');

        // Note: no early return on an empty $rows here — unlike a missing
        // file (readCsv's own guard), an all-rows-skipped result (e.g. no
        // score_categories/score_factors/room_judges seeded yet, since none
        // of those three have a seeder of their own) still needs the warn()
        // below to reach the operator. array_chunk([], 500) is a no-op, so
        // this is safe either way.

        // Chunked to stay well under MySQL's prepared-statement placeholder
        // limit, same reason CandidateSeeder/RecordingSeeder chunk.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('scores')->upsert(
                $chunk,
                ['id'],
                [
                    'version_id', 'candidate_id', 'student_id', 'school_id',
                    'score_category_id', 'score_category_order_by',
                    'score_factor_id', 'score_factor_order_by',
                    'judge_id', 'judge_order_by',
                    'voice_part_id', 'voice_part_order_by',
                    'score', 'updated_at',
                ]
            );
        }

        if ($this->skippedCount > 0) {
            $this->command->warn("ScoreSeeder skipped {$this->skippedCount} row(s) referencing a version/candidate/student/school/score_category/score_factor/room_judge/voice_part not found in the currently-seeded reference tables.");
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readCsv(string $filename): array
    {
        $path = database_path("seeders/data/{$filename}");

        if (! file_exists($path)) {
            $this->command->warn("ScoreSeeder skipped {$filename}: {$path} not found.");

            return [];
        }

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $now = now();
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) !== count($header)) {
                continue;
            }

            $row = array_combine($header, $data);

            $versionId = (int) $row['version_id'];
            $candidateId = (int) $row['candidate_id'];
            $studentId = (int) $row['student_id'];
            $schoolId = (int) $row['school_id'];
            $scoreCategoryId = (int) $row['score_category_id'];
            $scoreFactorId = (int) $row['score_factor_id'];
            $judgeId = (int) $row['judge_id'];
            $voicePartId = (int) $row['voice_part_id'];

            if (
                ! isset($this->existingVersionIds[$versionId])
                || ! isset($this->existingCandidateIds[$candidateId])
                || ! isset($this->existingStudentIds[$studentId])
                || ! isset($this->existingSchoolIds[$schoolId])
                || ! isset($this->existingScoreCategoryIds[$scoreCategoryId])
                || ! isset($this->existingScoreFactorIds[$scoreFactorId])
                || ! isset($this->existingRoomJudgeIds[$judgeId])
                || ! isset($this->existingVoicePartIds[$voicePartId])
            ) {
                $this->skippedCount++;

                continue;
            }

            $createdAt = $this->parseDate($row['created_at']) ?? $this->parseDate($row['updated_at']);

            $rows[] = [
                'id' => (int) $row['id'],
                'version_id' => $versionId,
                'candidate_id' => $candidateId,
                'student_id' => $studentId,
                'school_id' => $schoolId,
                'score_category_id' => $scoreCategoryId,
                'score_category_order_by' => (int) $row['score_category_order_by'],
                'score_factor_id' => $scoreFactorId,
                'score_factor_order_by' => (int) $row['score_factor_order_by'],
                'judge_id' => $judgeId,
                'judge_order_by' => (int) $row['judge_order_by'],
                'voice_part_id' => $voicePartId,
                'voice_part_order_by' => (int) $row['voice_part_order_by'],
                'score' => (int) $row['score'],
                'created_at' => $createdAt ?? $now,
                'updated_at' => $this->parseDate($row['updated_at']) ?? $now,
            ];
        }

        fclose($handle);

        return $rows;
    }

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '' || $value === 'NULL' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        $date = Carbon::createFromFormat('m/d/y H:i', $value);

        // Same US spring-forward DST guard as CandidateSeeder/RecordingSeeder
        // — these are audit-only timestamps, not used in date-math-sensitive logic.
        if ($date->hour === 2) {
            $date->addHour();
        }

        return $date;
    }
}
