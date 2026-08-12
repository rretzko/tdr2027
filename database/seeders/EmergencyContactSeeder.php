<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\PhoneNormalizer;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmergencyContactSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/emergency_contacts.csv)
     * that is gitignored and not pushed to the repository. Skips silently
     * when the file is absent so other environments are unaffected.
     */
    public function run(): void
    {
        $rows = $this->readCsv('emergency_contacts.csv');

        if ($rows === []) {
            return;
        }

        DB::table('emergency_contacts')->upsert(
            $rows,
            ['id'],
            [
                'student_id', 'name', 'relationship', 'email',
                'cell_phone', 'home_phone', 'work_phone', 'best_phone',
                'updated_at',
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
            $this->command->warn("EmergencyContactSeeder skipped {$filename}: {$path} not found.");

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

            // The export contains thousands of entirely blank rows (no id,
            // no data at all) alongside the real records — skip them rather
            // than trying to seed empty contacts.
            if (trim($row['id']) === '') {
                continue;
            }

            $createdAt = $this->parseDate($row['created_at']) ?? $this->parseDate($row['updated_at']);

            $rows[] = [
                'id' => (int) $row['id'],
                'student_id' => (int) $row['student_id'],
                'name' => trim($row['name']),
                'relationship' => trim($row['relationship']),
                'email' => trim($row['email']),
                'cell_phone' => PhoneNormalizer::normalize($row['cell_phone']) ?? '',
                'home_phone' => PhoneNormalizer::normalize($row['home_phone']),
                'work_phone' => PhoneNormalizer::normalize($row['work_phone']),
                'best_phone' => $this->normalizeBestPhone($row['best_phone']),
                'created_at' => $createdAt ?? $now,
                'updated_at' => $this->parseDate($row['updated_at']) ?? $now,
            ];
        }

        fclose($handle);

        return $rows;
    }

    private function normalizeBestPhone(string $value): string
    {
        return match (trim($value)) {
            'home' => 'home',
            'work' => 'work',
            default => 'cell',
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
