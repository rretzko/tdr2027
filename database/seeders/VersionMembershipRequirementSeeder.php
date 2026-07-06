<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VersionMembershipRequirementSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/version_membership_requirements.csv)
     * that is gitignored and not pushed to the repository. Skips silently
     * when the file is absent so other environments are unaffected.
     */
    public function run(): void
    {
        $rows = $this->readCsv('version_membership_requirements.csv');

        if ($rows === []) {
            return;
        }

        DB::table('version_membership_requirements')->upsert(
            $rows,
            ['id'],
            ['version_id', 'membership_card', 'valid_thru', 'updated_at']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readCsv(string $filename): array
    {
        $path = database_path("seeders/data/{$filename}");

        if (! file_exists($path)) {
            $this->command->warn("VersionMembershipRequirementSeeder skipped {$filename}: {$path} not found.");

            return [];
        }

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $now = now();
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $data);

            if (trim((string) $row['id']) === '' || trim((string) $row['version_id']) === '') {
                continue;
            }

            $rows[] = [
                'id' => (int) $row['id'],
                'version_id' => (int) $row['version_id'],
                'membership_card' => (bool) $row['membership_card'],
                'valid_thru' => $this->parseDateOnly($row['valid_thru'] ?? ''),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        fclose($handle);

        return $rows;
    }

    private function parseDateOnly(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '' || $value === 'NULL' || $value === '0000-00-00') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Exception) {
            return null;
        }
    }
}
