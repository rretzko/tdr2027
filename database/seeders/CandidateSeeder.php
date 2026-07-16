<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CandidateSeeder extends Seeder
{
    /**
     * Legacy statuses with no equivalent in the current CandidateStatus enum
     * (eligible/pending/registered/withdrew/teacher_withdrawn/adjudicated/
     * no_show/incomplete/accepted/not_accepted/declined/removed) are folded
     * into `pending` — both represent "some but not all registration
     * milestones met" (§6.2).
     *
     * @var array<string, string>
     */
    private const LEGACY_STATUS_MAP = [
        'engaged' => 'pending',
        'unsigned' => 'pending',
    ];

    /**
     * The CSV's `voice_part` column is the voice_parts.name string directly
     * rather than a legacy numeric id space (unlike StudentSeeder, which
     * still resolves via LEGACY_VOICE_PART_NAMES) — this maps the one known
     * typo in the export to the real name rather than dropping those rows.
     *
     * @var array<string, string>
     */
    private const VOICE_PART_NAME_FIXUPS = [
        'Low Baratone' => 'Low Baritone',
    ];

    /**
     * @var array<string, int>
     */
    private array $voicePartIdsByName = [];

    /**
     * @var array<int, true>
     */
    private array $existingStudentIds = [];

    /**
     * @var array<int, true>
     */
    private array $existingVersionIds = [];

    /**
     * @var array<int, true>
     */
    private array $existingSchoolIds = [];

    /**
     * @var array<int, true>
     */
    private array $existingTeacherIds = [];

    private int $skippedCount = 0;

    private int $duplicateRefSkippedCount = 0;

    /**
     * @var array<string, true>
     */
    private array $seenRefs = [];

    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/candidates.csv)
     * that is gitignored and not pushed to the repository. Skips silently
     * when the file is absent so other environments are unaffected.
     *
     * The CSV's emergency_contact_id is always nulled out: it references a
     * legacy emergency_contacts table this seeder set never populates (no
     * EmergencyContactSeeder/data file exists), so a non-null value here
     * would violate the FK constraint rather than resolve to real data.
     *
     * A meaningful fraction of rows reference a student_id, version_id,
     * school_id, or teacher_id that isn't present in this project's other
     * CSV exports/tables (students.csv/versions.csv/schools.csv/teachers.csv
     * appear to be a less complete snapshot than candidates.csv) — those
     * rows are skipped rather than failing the whole import, with a summary
     * warning at the end.
     *
     * A small number of rows (196 in the current export) reuse a `ref` value
     * already claimed by a different `id` — a genuine legacy data anomaly,
     * since `ref` is unique. upsert()'s conflict target is `id`, so MySQL's
     * ON DUPLICATE KEY UPDATE happens to tolerate this (it resolves *any*
     * unique-key collision, silently updating whichever row it matched),
     * but SQLite's ON CONFLICT(id) does not extend to unrelated unique
     * columns and hard-fails instead. First occurrence wins; every
     * subsequent row reusing that `ref` is skipped rather than relying on
     * that MySQL-specific tolerance.
     */
    public function run(): void
    {
        $this->voicePartIdsByName = DB::table('voice_parts')->pluck('id', 'name')->all();
        $this->existingStudentIds = array_fill_keys(DB::table('students')->pluck('id')->all(), true);
        $this->existingVersionIds = array_fill_keys(DB::table('versions')->pluck('id')->all(), true);
        $this->existingSchoolIds = array_fill_keys(DB::table('schools')->pluck('id')->all(), true);
        $this->existingTeacherIds = array_fill_keys(DB::table('teachers')->pluck('id')->all(), true);

        $rows = $this->readCsv('candidates.csv');

        if ($rows === []) {
            return;
        }

        // Chunked: at ~9k rows × 12 columns, a single upsert() exceeds MySQL's
        // prepared-statement placeholder limit ("General error: 1390").
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('candidates')->upsert(
                $chunk,
                ['id'],
                [
                    'ref', 'student_id', 'version_id', 'school_id', 'teacher_id',
                    'voice_part_id', 'status', 'program_name', 'emergency_contact_id',
                    'updated_at',
                ]
            );
        }

        if ($this->skippedCount > 0) {
            $this->command->warn("CandidateSeeder skipped {$this->skippedCount} row(s) referencing a student/version/school/teacher/voice part not found in the currently-seeded reference tables.");
        }

        if ($this->duplicateRefSkippedCount > 0) {
            $this->command->warn("CandidateSeeder skipped {$this->duplicateRefSkippedCount} row(s) reusing a ref already claimed by a different candidate id.");
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readCsv(string $filename): array
    {
        $path = database_path("seeders/data/{$filename}");

        if (! file_exists($path)) {
            $this->command->warn("CandidateSeeder skipped {$filename}: {$path} not found.");

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

            $studentId = (int) $row['student_id'];
            $versionId = (int) $row['version_id'];
            $schoolId = (int) $row['school_id'];
            $teacherId = (int) $row['teacher_id'];
            $voicePartId = $this->resolveVoicePartId($row['voice_part']);

            if (
                $voicePartId === null
                || ! isset($this->existingStudentIds[$studentId])
                || ! isset($this->existingVersionIds[$versionId])
                || ! isset($this->existingSchoolIds[$schoolId])
                || ! isset($this->existingTeacherIds[$teacherId])
            ) {
                $this->skippedCount++;

                continue;
            }

            $ref = trim($row['ref']);

            if (isset($this->seenRefs[$ref])) {
                $this->duplicateRefSkippedCount++;

                continue;
            }

            $this->seenRefs[$ref] = true;

            $createdAt = $this->parseDate($row['created_at']) ?? $this->parseDate($row['updated_at']);

            $rows[] = [
                'id' => (int) $row['id'],
                'ref' => $ref,
                'student_id' => $studentId,
                'version_id' => $versionId,
                'school_id' => $schoolId,
                'teacher_id' => $teacherId,
                'voice_part_id' => $voicePartId,
                'status' => $this->normalizeStatus($row['status']),
                'program_name' => trim($row['program_name']),
                'emergency_contact_id' => null,
                'created_at' => $createdAt ?? $now,
                'updated_at' => $this->parseDate($row['updated_at']) ?? $now,
            ];
        }

        fclose($handle);

        return $rows;
    }

    private function resolveVoicePartId(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $name = self::VOICE_PART_NAME_FIXUPS[$value] ?? $value;

        return $this->voicePartIdsByName[$name] ?? null;
    }

    private function normalizeStatus(string $value): string
    {
        $value = trim($value);

        return self::LEGACY_STATUS_MAP[$value] ?? $value;
    }

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '' || $value === 'NULL' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        $date = Carbon::createFromFormat('m/d/y H:i', $value);

        // A handful of rows land in the 2:00–2:59 AM hour, which on the US
        // "spring forward" DST date doesn't exist as a wall-clock time —
        // MySQL's server timezone rejects it outright on insert ("Incorrect
        // datetime value"). These are audit-only timestamps (not used in any
        // date-math-sensitive logic), so nudging the whole 2 AM hour forward
        // by an hour is a safe, simple way to guarantee no row can hit the
        // gap, without needing to calculate the exact DST transition date.
        if ($date->hour === 2) {
            $date->addHour();
        }

        return $date;
    }
}
