<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StudentTeacherSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/student_teacher.csv)
     * that is gitignored and not pushed to the repository. Skips silently
     * when the file is absent so other environments are unaffected.
     */
    public function run(): void
    {
        $rows = $this->readCsv('student_teacher.csv');

        if ($rows === []) {
            return;
        }

        $rows = $this->dropOrphanedRows($rows);

        if ($rows === []) {
            return;
        }

        DB::table('student_teacher')->upsert(
            $rows,
            ['id'],
            ['student_id', 'teacher_id', 'school_id', 'subject', 'role', 'is_active', 'updated_at']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readCsv(string $filename): array
    {
        $path = database_path("seeders/data/{$filename}");

        if (! file_exists($path)) {
            $this->command->warn("StudentTeacherSeeder skipped {$filename}: {$path} not found.");

            return [];
        }

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $now = now();
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $data);

            if (trim((string) $row['id']) === '') {
                continue;
            }

            $rows[] = [
                'id' => (int) $row['id'],
                'student_id' => (int) $row['student_id'],
                'teacher_id' => (int) $row['teacher_id'],
                'school_id' => (int) $row['school_id'],
                'subject' => trim($row['subject']),
                'role' => trim($row['role']),
                'is_active' => (bool) (int) $row['is_active'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * The source export references a handful of school_id/student_id values
     * that don't exist in schools.csv or students.csv, which would
     * otherwise trip the foreign key constraints on this table.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function dropOrphanedRows(array $rows): array
    {
        $schoolIds = DB::table('schools')->pluck('id')->all();
        $studentIds = DB::table('students')->pluck('id')->all();
        $teacherIds = DB::table('teachers')->pluck('id')->all();

        return array_values(array_filter($rows, function (array $row) use ($schoolIds, $studentIds, $teacherIds): bool {

            if (! in_array($row['school_id'], $schoolIds, true) || ! in_array($row['student_id'], $studentIds, true) || ! in_array($row['teacher_id'], $teacherIds, true)) {

                $this->command->warn("StudentTeacherSeeder skipped row id {$row['id']}: school_id {$row['school_id']} or student_id {$row['student_id']} or teacher_id {$row['teacher_id']} not found.");

                return false;
            }

            return true;
        }));
    }
}
