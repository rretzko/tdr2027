<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SchoolType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SchoolSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Reads from local-only CSV exports (database/seeders/data/schools.csv
     * and studios.csv) that are gitignored and not pushed to the repository.
     * Skips silently when a file is absent so other environments are unaffected.
     */
    public function run(): void
    {
        $rows = [
            ...$this->readCsv('schools.csv', SchoolType::School),
            ...$this->readCsv('studios.csv', SchoolType::Studio),
        ];

        if ($rows === []) {
            return;
        }

        DB::table('schools')->upsert(
            $rows,
            ['id'],
            ['name', 'type', 'city', 'zip_code', 'geostate_id', 'county_id', 'school_year', 'updated_at']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readCsv(string $filename, SchoolType $type): array
    {
        $path = database_path("seeders/data/{$filename}");

        if (! file_exists($path)) {
            $this->command->warn("SchoolSeeder skipped {$filename}: {$path} not found.");

            return [];
        }

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle);
        $now = now();
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $data);

            $rows[] = [
                'id' => (int) $row['id'],
                'name' => trim($row['name']),
                'type' => $type,
                'city' => trim($row['city']),
                'zip_code' => trim($row['zip_code']),
                'geostate_id' => $row['geostate_id'] !== '' ? (int) $row['geostate_id'] : null,
                'county_id' => (int) $row['county_id'],
                'school_year' => trim($row['school_year']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        fclose($handle);

        return $rows;
    }
}
