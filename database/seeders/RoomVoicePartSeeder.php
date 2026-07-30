<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoomVoicePartSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Reads from a local-only CSV export (database/seeders/data/room_voice_parts.csv)
     * that is gitignored and not pushed to the repository. Skips silently
     * when the file is absent so other environments are unaffected.
     */
    public function run(): void
    {
        $rows = $this->readCsv('room_voice_parts.csv');

        if ($rows === []) {
            return;
        }

        DB::table('room_voice_parts')->upsert(
            $rows,
            ['id'],
            ['room_id', 'voice_part_id', 'updated_at']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readCsv(string $filename): array
    {
        $path = database_path("seeders/data/{$filename}");

        if (! file_exists($path)) {
            $this->command->warn("RoomVoicePartSeeder skipped {$filename}: {$path} not found.");

            return [];
        }

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $now = now();
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $data);

            $rows[] = [
                'id' => (int) $row['id'],
                'room_id' => (int) $row['room_id'],
                'voice_part_id' => (int) $row['voice_part_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        fclose($handle);

        return $rows;
    }
}
