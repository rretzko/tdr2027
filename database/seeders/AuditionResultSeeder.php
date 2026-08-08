<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AuditionResultSeeder extends Seeder
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
    private array $existingVoicePartIds = [];

    /**
     * @var array<int, true>
     */
    private array $existingSchoolIds = [];

    private int $skippedCount = 0;

    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/audition_results.csv)
     * that is gitignored and not pushed to the repository. Skips silently
     * when the file is absent so other environments are unaffected.
     *
     * The CSV is the legacy system's own live-running-tally table, which
     * also carried `accepted`/`acceptance_abbr` (the cut-off/ensemble
     * assignment outcome). Those columns are intentionally NOT imported —
     * this schema's `audition_results` is a frozen score-tally snapshot
     * only (see AuditionResult's docblock); ensemble-assignment outcome
     * belongs on Candidate once the cut-off/assignment phase (§7.4-§7.5)
     * is built.
     *
     * Rows referencing a candidate_id/version_id/voice_part_id/school_id
     * not present in the currently-seeded reference tables are skipped
     * rather than failing the whole import, matching CandidateSeeder's
     * and RecordingSeeder's convention.
     */
    public function run(): void
    {
        $this->existingVersionIds = array_fill_keys(DB::table('versions')->pluck('id')->all(), true);
        $this->existingCandidateIds = array_fill_keys(DB::table('candidates')->pluck('id')->all(), true);
        $this->existingVoicePartIds = array_fill_keys(DB::table('voice_parts')->pluck('id')->all(), true);
        $this->existingSchoolIds = array_fill_keys(DB::table('schools')->pluck('id')->all(), true);

        $rows = $this->readCsv('audition_results.csv');

        // Note: no early return on an empty $rows here — unlike a missing
        // file (readCsv's own guard), an all-rows-skipped result still needs
        // the warn() below to reach the operator. array_chunk([], 500) is a
        // no-op, so this is safe either way.

        // Chunked to stay well under MySQL's prepared-statement placeholder
        // limit, same reason CandidateSeeder/RecordingSeeder chunk.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('audition_results')->upsert(
                $chunk,
                ['id'],
                ['candidate_id', 'version_id', 'voice_part_id', 'school_id', 'voice_part_order_by', 'score_count', 'total', 'updated_at']
            );
        }

        if ($this->skippedCount > 0) {
            $this->command->warn("AuditionResultSeeder skipped {$this->skippedCount} row(s) referencing a candidate/version/voice_part/school not found in the currently-seeded reference tables.");
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readCsv(string $filename): array
    {
        $path = database_path("seeders/data/{$filename}");

        if (! file_exists($path)) {
            $this->command->warn("AuditionResultSeeder skipped {$filename}: {$path} not found.");

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

            $candidateId = (int) $row['candidate_id'];
            $versionId = (int) $row['version_id'];
            $voicePartId = (int) $row['voice_part_id'];
            $schoolId = (int) $row['school_id'];

            if (
                ! isset($this->existingCandidateIds[$candidateId])
                || ! isset($this->existingVersionIds[$versionId])
                || ! isset($this->existingVoicePartIds[$voicePartId])
                || ! isset($this->existingSchoolIds[$schoolId])
            ) {
                $this->skippedCount++;

                continue;
            }

            $createdAt = $this->parseDate($row['created_at']) ?? $this->parseDate($row['updated_at']);

            $rows[] = [
                'id' => (int) $row['id'],
                'candidate_id' => $candidateId,
                'version_id' => $versionId,
                'voice_part_id' => $voicePartId,
                'school_id' => $schoolId,
                'voice_part_order_by' => (int) $row['voice_part_order_by'],
                'score_count' => (int) $row['score_count'],
                'total' => (int) $row['total'],
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
