<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SchoolStudentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/school_student.csv)
     * that is gitignored and not pushed to the repository. Skips silently
     * when the file is absent so other environments are unaffected.
     */
    public function run(): void
    {
        $rows = $this->readCsv('school_student.csv');

        if ($rows === []) {
            return;
        }

        $rows = $this->dropOrphanedRows($rows);

        if ($rows === []) {
            return;
        }

        DB::table('school_student')->upsert(
            $rows,
            ['id'],
            ['school_id', 'student_id', 'is_active', 'class_of', 'updated_at']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readCsv(string $filename): array
    {
        $path = database_path("seeders/data/{$filename}");

        if (! file_exists($path)) {
            $this->command->warn("SchoolStudentSeeder skipped {$filename}: {$path} not found.");

            return [];
        }

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $now = now();
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $data);

            $rows[] = [
                'id' => (int) $row['id'],
                'school_id' => (int) $row['school_id'],
                'student_id' => (int) $row['student_id'],
                'is_active' => (bool) (int) $row['is_active'],
                'class_of' => (int) $row['class_of'],
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

        return array_values(array_filter($rows, function (array $row) use ($schoolIds, $studentIds): bool {
            if (! in_array($row['school_id'], $schoolIds, true) || ! in_array($row['student_id'], $studentIds, true)) {
                $this->command->warn("SchoolStudentSeeder skipped row id {$row['id']}: school_id {$row['school_id']} or student_id {$row['student_id']} not found.");

                return false;
            }

            return true;
        }));
    }
}
