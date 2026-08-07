<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RecordingSeeder extends Seeder
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
    private array $existingUserIds = [];

    private int $skippedCount = 0;

    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/recordings.csv)
     * that is gitignored and not pushed to the repository. Skips silently
     * when the file is absent so other environments are unaffected.
     *
     * The CSV's `approved` timestamp column is seeded as `approved_at`, to
     * match this app's `_at` timestamp-column convention. A row with a blank
     * `approved`/`approved_by` seeds both as null — that's the "not yet
     * approved" state judges never see, not a data error.
     *
     * Rows referencing a version_id/candidate_id/uploaded_by/approved_by not
     * present in the currently-seeded reference tables are skipped rather
     * than failing the whole import, matching CandidateSeeder's convention.
     */
    public function run(): void
    {
        $this->existingVersionIds = array_fill_keys(DB::table('versions')->pluck('id')->all(), true);
        $this->existingCandidateIds = array_fill_keys(DB::table('candidates')->pluck('id')->all(), true);
        $this->existingUserIds = array_fill_keys(DB::table('users')->pluck('id')->all(), true);

        $rows = $this->readCsv('recordings.csv');

        if ($rows === []) {
            return;
        }

        // Chunked to stay well under MySQL's prepared-statement placeholder
        // limit, same reason CandidateSeeder chunks at 500 (~9k rows there;
        // recordings.csv is a similar order of magnitude).
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('recordings')->upsert(
                $chunk,
                ['id'],
                ['version_id', 'candidate_id', 'file_type', 'uploaded_by', 'approved_at', 'approved_by', 'url', 'updated_at']
            );
        }

        if ($this->skippedCount > 0) {
            $this->command->warn("RecordingSeeder skipped {$this->skippedCount} row(s) referencing a version/candidate/user not found in the currently-seeded reference tables.");
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readCsv(string $filename): array
    {
        $path = database_path("seeders/data/{$filename}");

        if (! file_exists($path)) {
            $this->command->warn("RecordingSeeder skipped {$filename}: {$path} not found.");

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
            $uploadedBy = (int) $row['uploaded_by'];
            $approvedBy = $this->nullableInt($row['approved_by'] ?? '');

            if (
                ! isset($this->existingVersionIds[$versionId])
                || ! isset($this->existingCandidateIds[$candidateId])
                || ! isset($this->existingUserIds[$uploadedBy])
                || ($approvedBy !== null && ! isset($this->existingUserIds[$approvedBy]))
            ) {
                $this->skippedCount++;

                continue;
            }

            $createdAt = $this->parseDate($row['created_at']) ?? $this->parseDate($row['updated_at']);

            $rows[] = [
                'id' => (int) $row['id'],
                'version_id' => $versionId,
                'candidate_id' => $candidateId,
                'file_type' => trim($row['file_type']),
                'uploaded_by' => $uploadedBy,
                'approved_at' => $this->parseDate($row['approved'] ?? ''),
                'approved_by' => $approvedBy,
                'url' => trim($row['url']),
                'created_at' => $createdAt ?? $now,
                'updated_at' => $this->parseDate($row['updated_at']) ?? $now,
            ];
        }

        fclose($handle);

        return $rows;
    }

    private function nullableInt(string $value): ?int
    {
        $value = trim($value);

        return ($value === '' || strcasecmp($value, 'null') === 0) ? null : (int) $value;
    }

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '' || $value === 'NULL' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        $date = Carbon::createFromFormat('m/d/y H:i', $value);

        // Same US spring-forward DST guard as CandidateSeeder — these are
        // audit-only timestamps, not used in any date-math-sensitive logic.
        if ($date->hour === 2) {
            $date->addHour();
        }

        return $date;
    }
}
