<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\VoicePart;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VersionPitchFileSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/version_pitch_files.csv)
     * that is gitignored and not pushed to the repository. Skips silently
     * when the file is absent so other environments are unaffected.
     *
     * The legacy export references a version_id column that doesn't always
     * exist in this environment's versions table (blank or stale legacy
     * ids), and a voice_part_id column that actually holds the voice part's
     * *name* (not a legacy numeric id — see EnsembleVoicePartSeeder for the
     * same convention). Rows with a missing version_id or unknown voice
     * part name are skipped with a warning rather than failing the whole
     * run on the version_pitch_files FK constraints.
     */
    public function run(): void
    {
        $versionIds = DB::table('versions')->pluck('id')->all();
        $voicePartIds = VoicePart::pluck('id', 'name');

        $rows = $this->readCsv('version_pitch_files.csv', $versionIds, $voicePartIds);

        if ($rows === []) {
            return;
        }

        DB::table('version_pitch_files')->upsert(
            $rows,
            ['id'],
            [
                'version_id', 'voice_part_id', 'name', 'description', 'url',
                'order_by', 'updated_at',
            ]
        );
    }

    /**
     * @param  array<int, int>  $versionIds
     * @param  Collection<string, int>  $voicePartIds
     * @return array<int, array<string, mixed>>
     */
    private function readCsv(string $filename, array $versionIds, Collection $voicePartIds): array
    {
        $path = database_path("seeders/data/{$filename}");

        if (! file_exists($path)) {
            $this->command->warn("VersionPitchFileSeeder skipped {$filename}: {$path} not found.");

            return [];
        }

        $versionIds = array_flip($versionIds);

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $now = now();
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $data);

            $versionId = $this->nullableInt($row['version_id'] ?? '');

            if ($versionId === null || ! isset($versionIds[$versionId])) {
                $this->command->warn("VersionPitchFileSeeder skipped row {$row['id']}: missing version_id.");

                continue;
            }

            $voicePartName = trim((string) ($row['voice_part_id'] ?? ''));
            $voicePartId = $voicePartIds[$voicePartName] ?? null;

            if ($voicePartId === null) {
                $this->command->warn("VersionPitchFileSeeder skipped row {$row['id']}: unknown voice part \"{$voicePartName}\".");

                continue;
            }

            $createdAt = $this->parseDate($row['created_at']) ?? $this->parseDate($row['updated_at']);

            $rows[] = [
                'id' => (int) $row['id'],
                'version_id' => $versionId,
                'voice_part_id' => $voicePartId,
                'name' => trim($row['name']),
                'description' => $this->nullableTrim($row['description'] ?? ''),
                'url' => trim($row['url']),
                'order_by' => (int) $row['order_by'],
                'created_at' => $createdAt ?? $now,
                'updated_at' => $this->parseDate($row['updated_at']) ?? $now,
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

    private function nullableInt(string $value): ?int
    {
        $value = trim($value);

        return ($value === '' || strcasecmp($value, 'null') === 0) ? null : (int) $value;
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
