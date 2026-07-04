<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VersionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/versions.csv)
     * that is gitignored and not pushed to the repository. Skips silently
     * when the file is absent so other environments are unaffected.
     */
    public function run(): void
    {
        $rows = $this->readCsv('versions.csv');

        if ($rows === []) {
            return;
        }

        DB::table('versions')->upsert(
            $rows,
            ['id'],
            [
                'event_id', 'name', 'short_name', 'senior_class_of', 'status',
                'application_type', 'audition_timeslot', 'audition_type', 'birthday',
                'upload_type', 'emergency_contact_name', 'emergency_contact_cell',
                'emergency_contact_email', 'height', 'home_address', 'judge_count',
                'max_registrants', 'max_upper_voice_registrants', 'pitch_file_visibility',
                'release_confidential_results', 'score_order', 'shirt_size', 'teacher_cell',
                'updated_at', 'deleted_at',
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
            $this->command->warn("VersionSeeder skipped {$filename}: {$path} not found.");

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
                'event_id' => (int) $row['event_id'],
                'name' => trim($row['name']),
                'short_name' => $this->nullableTrim($row['short_name'] ?? ''),
                'senior_class_of' => (int) $row['senior_class_of'],
                'status' => trim($row['status']),
                'application_type' => trim($row['application_type']),
                'audition_timeslot' => $this->nullableInt($row['audition_timeslot'] ?? ''),
                'audition_type' => trim($row['audition_type']),
                'birthday' => (bool) $row['birthday'],
                'upload_type' => trim($row['upload_type']),
                'emergency_contact_name' => (bool) $row['emergency_contact_name'],
                'emergency_contact_cell' => (bool) $row['emergency_contact_cell'],
                'emergency_contact_email' => (bool) $row['emergency_contact_email'],
                'height' => (bool) $row['height'],
                'home_address' => (bool) $row['home_address'],
                'judge_count' => (int) $row['judge_count'],
                'max_registrants' => $this->nullableInt($row['max_registrants'] ?? ''),
                'max_upper_voice_registrants' => $this->nullableInt($row['max_upper_voice_registrants'] ?? ''),
                'pitch_file_visibility' => trim($row['pitch_file_visibility']),
                'release_confidential_results' => (bool) $row['release_confidential_results'],
                'score_order' => trim($row['score_order']),
                'shirt_size' => (bool) $row['shirt_size'],
                'teacher_cell' => (bool) $row['teacher_cell'],
                'created_at' => $createdAt ?? $now,
                'updated_at' => $this->parseDate($row['updated_at']) ?? $now,
                'deleted_at' => $this->parseDate($row['deleted_at'] ?? ''),
            ];
        }

        fclose($handle);

        return $rows;
    }

    private function nullableTrim(string $value): ?string
    {
        $value = trim($value);

        return ($value === '' || $value === 'NULL') ? null : $value;
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
