<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Services\VersionRoleService;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class VersionRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/version_roles.csv)
     * that is gitignored and not pushed to the repository. Skips silently
     * when the file is absent so other environments are unaffected.
     *
     * Assigns each row's role scoped to that row's Version, via the same
     * VersionRoleService::withVersion() path the app uses everywhere else —
     * not a raw table insert, so Spatie's own dedupe/cache handling applies.
     * Rows referencing an unknown role or an email with no matching user are
     * skipped with a warning rather than failing the whole run, since this is
     * a one-time historical import over data with known gaps.
     */
    public function run(VersionRoleService $versionRoles): void
    {
        $rows = $this->readCsv('version_roles.csv');

        if ($rows === []) {
            return;
        }

        $roleNames = Role::where('guard_name', 'web')->pluck('name')->all();

        foreach ($rows as $row) {
            if (! in_array($row['role'], $roleNames, true)) {
                $this->command->warn("VersionRoleSeeder skipped: unknown role \"{$row['role']}\" (version {$row['version_id']}, {$row['email']}).");

                continue;
            }

            $user = User::where('email', $row['email'])->first();

            if ($user === null) {
                $this->command->warn("VersionRoleSeeder skipped: no user found for {$row['email']} (version {$row['version_id']}, role {$row['role']}).");

                continue;
            }

            $versionRoles->withVersion($row['version_id'], fn () => $user->assignRole($row['role']));
        }
    }

    /**
     * @return list<array{version_id: int, role: string, email: string}>
     */
    private function readCsv(string $filename): array
    {
        $path = database_path("seeders/data/{$filename}");

        if (! file_exists($path)) {
            $this->command->warn("VersionRoleSeeder skipped {$filename}: {$path} not found.");

            return [];
        }

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $data);

            if (trim((string) $row['version_id']) === '') {
                continue;
            }

            $rows[] = [
                'version_id' => (int) $row['version_id'],
                'role' => trim((string) $row['role']),
                'email' => trim((string) $row['email']),
            ];
        }

        fclose($handle);

        return $rows;
    }
}
