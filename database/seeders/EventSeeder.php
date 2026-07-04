<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EventSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/events.csv)
     * that is gitignored and not pushed to the repository. Skips silently
     * when the file is absent so other environments are unaffected.
     */
    public function run(): void
    {
        $rows = $this->readCsv('events.csv');

        if ($rows === []) {
            return;
        }

        DB::table('events')->upsert(
            $rows,
            ['id'],
            [
                'organization_id', 'name', 'short_name', 'logo_url', 'logo_alt',
                'status', 'frequency', 'audition_count', 'ensemble_count',
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
            $this->command->warn("EventSeeder skipped {$filename}: {$path} not found.");

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
                'organization_id' => (int) $row['organization_id'],
                'name' => trim($row['name']),
                'short_name' => $this->nullableTrim($row['short_name'] ?? ''),
                'logo_url' => $this->nullableTrim($row['logo_url'] ?? ''),
                'logo_alt' => $this->nullableTrim($row['logo_alt'] ?? ''),
                'status' => trim($row['status']),
                'frequency' => trim($row['frequency']),
                'audition_count' => (int) $row['audition_count'],
                'ensemble_count' => (int) $row['ensemble_count'],
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

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '' || $value === 'NULL' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        return Carbon::createFromFormat('m/d/y H:i', $value);
    }
}
