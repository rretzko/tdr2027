<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TeacherRole;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SchoolTeacherSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/school_teacher.csv)
     * that is gitignored and not pushed to the repository. Skips silently
     * when the file is absent so other environments are unaffected.
     */
    public function run(): void
    {
        $rows = $this->readCsv('school_teacher.csv');

        if ($rows === []) {
            return;
        }

        $rows = $this->dropOrphanedRows($rows);

        if ($rows === []) {
            return;
        }

        DB::table('school_teacher')->upsert(
            $rows,
            ['id'],
            [
                'school_id', 'teacher_id', 'role', 'school_email',
                'verified_at', 'is_active', 'updated_at',
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readCsv(string $filename): array
    {
        $path = database_path("seeders/data/{$filename}");

        if (! file_exists($path)) {
            $this->command->warn("SchoolTeacherSeeder skipped {$filename}: {$path} not found.");

            return [];
        }

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $now = now();
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $data);

            $createdAt = $this->parseDate($row['created_at']) ?? $this->parseDate($row['updated_at']);

            $rows[] = [
                'id' => (int) $row['id'],
                'school_id' => (int) $row['school_id'],
                'teacher_id' => (int) $row['teacher_id'],
                'role' => $this->normalizeRole($row['role']),
                'school_email' => trim($row['school_email']) ?: null,
                'verified_at' => $this->parseDate($row['verified_at']),
                'is_active' => (bool) (int) $row['is_active'],
                'created_at' => $createdAt ?? $now,
                'updated_at' => $this->parseDate($row['updated_at']) ?? $now,
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * The source export references a handful of school_id/teacher_id values
     * that don't exist in schools.csv/studios.csv or teachers.csv, which
     * would otherwise trip the foreign key constraints on this table.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function dropOrphanedRows(array $rows): array
    {
        $schoolIds = DB::table('schools')->pluck('id')->all();
        $teacherIds = DB::table('teachers')->pluck('id')->all();

        return array_values(array_filter($rows, function (array $row) use ($schoolIds, $teacherIds): bool {
            if (! in_array($row['school_id'], $schoolIds, true) || ! in_array($row['teacher_id'], $teacherIds, true)) {
                $this->command->warn("SchoolTeacherSeeder skipped row id {$row['id']}: school_id {$row['school_id']} or teacher_id {$row['teacher_id']} not found.");

                return false;
            }

            return true;
        }));
    }

    private function normalizeRole(string $value): ?string
    {
        return match (trim($value)) {
            'primary' => TeacherRole::Primary->value,
            'co-teacher' => TeacherRole::Coteacher->value,
            default => null,
        };
    }

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        return Carbon::createFromFormat('m/d/y H:i', $value);
    }
}
