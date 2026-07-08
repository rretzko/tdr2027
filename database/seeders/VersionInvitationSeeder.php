<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VersionInvitationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/version_invitations.csv)
     * that is gitignored and not pushed to the repository. Skips silently
     * when the file is absent so other environments are unaffected.
     *
     * The legacy export references version_id/teacher_id values that don't
     * all exist in this environment's versions/teachers tables (blank or
     * stale legacy ids). Rows with a missing version_id or teacher_id are
     * skipped with a warning rather than failing the whole run on the
     * version_invitations FK constraints.
     */
    public function run(): void
    {
        $versionIds = DB::table('versions')->pluck('id')->all();
        $teacherIds = DB::table('teachers')->pluck('id')->all();

        $rows = $this->readCsv('version_invitations.csv', $versionIds, $teacherIds);

        if ($rows === []) {
            return;
        }

        DB::table('version_invitations')->upsert(
            $rows,
            ['id'],
            [
                'version_id', 'teacher_id', 'status', 'invited_at',
                'invited_by_user_id', 'updated_at',
            ]
        );
    }

    /**
     * @param  array<int, int>  $versionIds
     * @param  array<int, int>  $teacherIds
     * @return array<int, array<string, mixed>>
     */
    private function readCsv(string $filename, array $versionIds, array $teacherIds): array
    {
        $path = database_path("seeders/data/{$filename}");

        if (! file_exists($path)) {
            $this->command->warn("VersionInvitationSeeder skipped {$filename}: {$path} not found.");

            return [];
        }

        $versionIds = array_flip($versionIds);
        $teacherIds = array_flip($teacherIds);

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $now = now();
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $data);

            $versionId = $this->nullableInt($row['version_id'] ?? '');

            if ($versionId === null || ! isset($versionIds[$versionId])) {
                $this->command->warn("VersionInvitationSeeder skipped row {$row['id']}: missing version_id.");

                continue;
            }

            $teacherId = $this->nullableInt($row['teacher_id'] ?? '');

            if ($teacherId === null || ! isset($teacherIds[$teacherId])) {
                $this->command->warn("VersionInvitationSeeder skipped row {$row['id']}: missing teacher_id.");

                continue;
            }

            $createdAt = $this->parseDate($row['created_at']) ?? $this->parseDate($row['updated_at']);

            $rows[] = [
                'id' => (int) $row['id'],
                'version_id' => $versionId,
                'teacher_id' => $teacherId,
                'status' => trim($row['status']),
                'invited_at' => $this->parseDate($row['invited_at']) ?? $now,
                'invited_by_user_id' => (int) $row['invited_by_user_id'],
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

        return Carbon::createFromFormat('m/d/y H:i', $value);
    }
}
