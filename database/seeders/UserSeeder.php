<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\PhoneNormalizer;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserSeeder extends Seeder
{
    /**
     * Phone numbers already assigned to a row in this run, keyed by the
     * normalized value. The cell_phone column is unique, but the source
     * export contains real duplicates and junk placeholders ("#N/A", "0"),
     * so every value after the first occurrence is nulled out.
     *
     * @var array<string, true>
     */
    private array $seenPhones = [];

    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/users.csv)
     * that is gitignored and not pushed to the repository. Skips silently
     * when the file is absent so other environments are unaffected.
     */
    public function run(): void
    {
        $rows = $this->readCsv('users.csv');

        if ($rows === []) {
            return;
        }

        DB::table('users')->upsert(
            $rows,
            ['id'],
            [
                'name', 'honorific', 'first_name', 'middle_name', 'last_name', 'suffix_name',
                'email', 'cell_phone', 'pronoun_id', 'email_verified_at',
                'password', 'remember_token', 'updated_at',
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
            $this->command->warn("UserSeeder skipped {$filename}: {$path} not found.");

            return [];
        }

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle);
        $now = now();
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $data);

            $createdAt = $this->parseDate($row['created_at']) ?? $this->parseDate($row['updated_at']);

            $rows[] = [
                'id' => (int) $row['id'],
                'name' => trim($row['name']),
                'honorific' => null,
                'first_name' => trim($row['first_name']),
                'middle_name' => trim($row['middle_name']) ?: null,
                'last_name' => trim($row['last_name']),
                'suffix_name' => trim($row['suffix_name']) ?: null,
                'email' => trim($row['email']),
                'cell_phone' => $this->normalizePhone($row['cell_phone']),
                'pronoun_id' => (int) $row['pronoun_id'],
                'email_verified_at' => $this->parseDate($row['email_verified_at']),
                'password' => $row['password'],
                'remember_token' => trim($row['remember_token']) ?: null,
                'created_at' => $createdAt ?? $now,
                'updated_at' => $this->parseDate($row['updated_at']) ?? $now,
            ];
        }

        fclose($handle);

        return $rows;
    }

    private function normalizePhone(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || $value === '#N/A' || $value === '0') {
            return null;
        }

        $digits = PhoneNormalizer::normalize($value);

        if ($digits === null) {
            return null;
        }

        // The users table has no extension field, so anything beyond the
        // 10-digit number (e.g. trailing "ext 1" / "x3" in the export) is
        // dropped rather than merged into the stored digits.
        $digits = substr($digits, 0, 10);

        if (isset($this->seenPhones[$digits])) {
            return null;
        }

        $this->seenPhones[$digits] = true;

        return $digits;
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
