<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrganizationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/organizations.csv)
     * that is gitignored and not pushed to the repository. Skips silently when the
     * file is absent so other environments are unaffected.
     */
    public function run(): void
    {
        $rows = $this->readCsv('organizations.csv');

        if ($rows === []) {
            return;
        }

        // Insert with parent_id stripped first so self-referencing foreign keys
        // never point at a row that hasn't been created yet, regardless of CSV order.
        DB::table('organizations')->upsert(
            array_map(fn (array $row) => [...$row, 'parent_id' => null], $rows),
            ['id'],
            ['name', 'abbr', 'parent_id', 'logo_file_url', 'logo_file_alt', 'updated_at']
        );

        foreach ($rows as $row) {
            if ($row['parent_id'] !== null) {
                DB::table('organizations')->where('id', $row['id'])->update(['parent_id' => $row['parent_id']]);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readCsv(string $filename): array
    {
        $path = database_path("seeders/data/{$filename}");

        if (! file_exists($path)) {
            $this->command->warn("OrganizationSeeder skipped {$filename}: {$path} not found.");

            return [];
        }

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $now = now();
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $data);
            $parentId = trim((string) ($row['parent_id'] ?? ''));

            $rows[] = [
                'id' => (int) $row['id'],
                'name' => trim($row['name']),
                'abbr' => trim((string) ($row['abbr'] ?? '')) ?: null,
                'parent_id' => $parentId !== '' && $parentId !== '0' ? (int) $parentId : null,
                'logo_file_url' => trim((string) ($row['logo_file_url'] ?? '')) ?: null,
                'logo_file_alt' => trim((string) ($row['logo_file_alt'] ?? '')) ?: null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        fclose($handle);

        return $rows;
    }
}
