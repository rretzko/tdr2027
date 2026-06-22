<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ShirtSize;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StudentSeeder extends Seeder
{
    /**
     * The legacy export's voice_part_id uses a different id space than our
     * auto-incremented voice_parts table (e.g. legacy 63 = "Soprano I"), so
     * legacy ids are resolved to a voice_parts.id by name at runtime. Legacy
     * id 74 ("All") has no equivalent row and is intentionally left out,
     * resolving to null.
     *
     * @var array<int, string>
     */
    private const LEGACY_VOICE_PART_NAMES = [
        1 => 'Alto',
        2 => 'Baritone',
        3 => 'Bass',
        4 => 'Bass Baritone',
        5 => 'Soprano',
        6 => 'Tenor',
        63 => 'Soprano I',
        64 => 'Soprano II',
        65 => 'Alto I',
        66 => 'Alto II',
        67 => 'Tenor I',
        68 => 'Tenor II',
        69 => 'Bass I',
        70 => 'Bass II',
        71 => 'Descant',
        72 => 'High Baritone',
        73 => 'Low Baritone',
    ];

    /**
     * @var array<string, int>
     */
    private array $voicePartIdsByName = [];

    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/students.csv)
     * that is gitignored and not pushed to the repository. Skips silently
     * when the file is absent so other environments are unaffected.
     *
     * The CSV's class_of column belongs to the school_student pivot, not
     * this table, and is intentionally ignored here.
     */
    public function run(): void
    {
        $this->voicePartIdsByName = DB::table('voice_parts')->pluck('id', 'name')->all();

        $rows = $this->readCsv('students.csv');

        if ($rows === []) {
            return;
        }

        DB::table('students')->upsert(
            $rows,
            ['id'],
            ['user_id', 'voice_part_id', 'height', 'birthday', 'shirt_size', 'updated_at']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readCsv(string $filename): array
    {
        $path = database_path("seeders/data/{$filename}");

        if (! file_exists($path)) {
            $this->command->warn("StudentSeeder skipped {$filename}: {$path} not found.");

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

            $createdAt = $this->parseDate($row['created_at']) ?? $this->parseDate($row['updated_at']);

            $rows[] = [
                'id' => (int) $row['id'],
                'user_id' => (int) $row['user_id'],
                'voice_part_id' => $this->resolveVoicePartId($row['voice_part_id']),
                'height' => $row['height'] !== '' ? (int) $row['height'] : null,
                'birthday' => $this->parseBirthday($row['birthday']),
                'shirt_size' => $this->normalizeShirtSize($row['shirt_size']),
                'created_at' => $createdAt ?? $now,
                'updated_at' => $this->parseDate($row['updated_at']) ?? $now,
            ];
        }

        fclose($handle);

        return $rows;
    }

    private function resolveVoicePartId(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $name = self::LEGACY_VOICE_PART_NAMES[(int) $value] ?? null;

        return $name !== null ? ($this->voicePartIdsByName[$name] ?? null) : null;
    }

    private function normalizeShirtSize(string $value): string
    {
        return match (trim($value)) {
            '2xs' => ShirtSize::XXS->value,
            'sx', 'xs' => ShirtSize::XS->value,
            'sm' => ShirtSize::SM->value,
            'lg' => ShirtSize::LG->value,
            'xl' => ShirtSize::XL->value,
            '2xl', 'xxl' => ShirtSize::XXL->value,
            '3xl' => ShirtSize::XXXL->value,
            '4xl' => ShirtSize::XXXXL->value,
            default => ShirtSize::MED->value,
        };
    }

    private function parseBirthday(string $value): ?Carbon
    {
        $value = trim($value);

        if (! preg_match('#^\d{1,2}/\d{1,2}/\d{2}$#', $value)) {
            return null;
        }

        return Carbon::createFromFormat('m/d/y', $value);
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
