<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\VoicePart;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EnsembleVoicePartSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/ensemble_voice_parts.csv)
     * that is gitignored and not pushed to the repository. Skips silently
     * when the file is absent so other environments are unaffected.
     *
     * Expects a `voice_part` column holding the voice part's name (not a
     * legacy numeric id — the legacy system's voice_part ids don't correspond
     * to VoicePartSeeder's ids here). Rows naming an unknown voice part are
     * skipped with a warning rather than failing the whole run.
     */
    public function run(): void
    {
        $rows = $this->readCsv('ensemble_voice_parts.csv');

        if ($rows === []) {
            return;
        }

        $voicePartIds = VoicePart::pluck('id', 'name');
        $toInsert = [];

        foreach ($rows as $row) {
            $voicePartId = $voicePartIds[$row['voice_part']] ?? null;

            if ($voicePartId === null) {
                $this->command->warn("EnsembleVoicePartSeeder skipped: unknown voice part \"{$row['voice_part']}\" (ensemble {$row['ensemble_id']}).");

                continue;
            }

            $toInsert[] = [
                'ensemble_id' => $row['ensemble_id'],
                'voice_part_id' => $voicePartId,
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
        }

        if ($toInsert === []) {
            return;
        }

        DB::table('ensemble_voice_parts')->upsert(
            $toInsert,
            ['ensemble_id', 'voice_part_id'],
            ['updated_at']
        );
    }

    /**
     * @return array<int, array{ensemble_id: int, voice_part: string, created_at: Carbon, updated_at: Carbon}>
     */
    private function readCsv(string $filename): array
    {
        $path = database_path("seeders/data/{$filename}");

        if (! file_exists($path)) {
            $this->command->warn("EnsembleVoicePartSeeder skipped {$filename}: {$path} not found.");

            return [];
        }

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

        if (! in_array('voice_part', $header, true)) {
            fclose($handle);
            $this->command->warn("EnsembleVoicePartSeeder skipped {$filename}: expected a \"voice_part\" column (found: ".implode(', ', $header).').');

            return [];
        }

        $now = now();
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $data);

            if (trim((string) $row['ensemble_id']) === '') {
                continue;
            }

            $createdAt = $this->parseDate($row['created_at']) ?? $this->parseDate($row['updated_at']) ?? $now;

            $rows[] = [
                'ensemble_id' => (int) $row['ensemble_id'],
                'voice_part' => trim((string) $row['voice_part']),
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
