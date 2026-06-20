<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TeacherSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/teachers.csv)
     * that is gitignored and not pushed to the repository. Skips silently when
     * the file is absent so other environments are unaffected.
     */
    public function run(): void
    {
        $rows = $this->readCsv('teachers.csv');

        if ($rows === []) {
            return;
        }

        DB::table('teachers')->upsert(
            $rows,
            ['id'],
            ['user_id', 'onboarding_step', 'onboarding_completed_at', 'updated_at']
        );

        // These are real, already-established teachers imported from the legacy
        // system, not new sign-ups — marking onboarding complete (above) keeps
        // EnsureTeacherOnboardingComplete from redirecting them into the wizard,
        // and assigning the role here mirrors what TeacherRegister does at signup.
        $userIds = array_column($rows, 'user_id');

        User::whereIn('id', $userIds)->get()->each(function (User $user) {
            $user->assignRole('Teacher');
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readCsv(string $filename): array
    {
        $path = database_path("seeders/data/{$filename}");

        if (! file_exists($path)) {
            $this->command->warn("TeacherSeeder skipped {$filename}: {$path} not found.");

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

            $createdAt = $this->parseDate($row['created_at']) ?? $this->parseDate($row['updated_at']) ?? $now;

            $rows[] = [
                'id' => (int) $row['id'],
                'user_id' => (int) $row['user_id'],
                'onboarding_step' => 1,
                'onboarding_completed_at' => $createdAt,
                'created_at' => $createdAt,
                'updated_at' => $this->parseDate($row['updated_at']) ?? $now,
            ];
        }

        fclose($handle);

        return $rows;
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
