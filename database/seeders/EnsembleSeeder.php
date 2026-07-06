<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EnsembleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/ensembles.csv)
     * that is gitignored and not pushed to the repository. Skips silently
     * when the file is absent so other environments are unaffected.
     */
    public function run(): void
    {
        $rows = $this->readCsv('ensembles.csv');

        if ($rows === []) {
            return;
        }

        DB::table('ensembles')->upsert(
            $rows,
            ['id'],
            ['event_id', 'name', 'short_name', 'abbreviation', 'updated_at', 'deleted_at']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readCsv(string $filename): array
    {
        $path = database_path("seeders/data/{$filename}");

        if (! file_exists($path)) {
            $this->command->warn("EnsembleSeeder skipped {$filename}: {$path} not found.");

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
                'abbreviation' => $this->nullableTrim($row['abbreviation'] ?? ''),
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
