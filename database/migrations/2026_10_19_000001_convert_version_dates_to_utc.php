<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * version_dates.start_at/end_at were saved as Eastern wall-clock values
 * straight from VersionEdit's datetime-local inputs, while every comparison
 * against now() (and config('app.timezone')) is UTC — so every window opened
 * and closed 4–5 hours early. This converts the stored values to UTC, the
 * convention every other datetime follows.
 *
 * The columns also move from TIMESTAMP to DATETIME first:
 * - MariaDB had silently given start_at `ON UPDATE current_timestamp()`,
 *   which overwrote it whenever an UPDATE didn't include it (Eloquent only
 *   writes dirty columns, so editing just end_at clobbered start_at — this
 *   happened to Version 88's candidate window on prod).
 * - TIMESTAMP converts through the session time zone on every read/write;
 *   DATETIME stores exactly what it's given.
 *
 * Production is applied by hand with the equivalent
 * database/sql/2026_10_19_convert_version_dates_to_utc.sql, which also
 * records this migration in `migrations` so it can never run a second time
 * (a second run would shift every date another 4–5 hours).
 */
return new class extends Migration
{
    private const WALL_CLOCK_TIMEZONE = 'America/New_York';

    public function up(): void
    {
        Schema::table('version_dates', function (Blueprint $table): void {
            $table->dateTime('start_at')->change();
            $table->dateTime('end_at')->nullable()->change();
        });

        $this->assertNoDstEdgeCases();

        $this->convert(fn (string $value): string => Carbon::parse($value, self::WALL_CLOCK_TIMEZONE)->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * Reverts the data only — the columns stay DATETIME, since going back to
     * TIMESTAMP would bring back the ON UPDATE clobbering of start_at.
     */
    public function down(): void
    {
        $this->convert(fn (string $value): string => Carbon::parse($value, 'UTC')->timezone(self::WALL_CLOCK_TIMEZONE)->format('Y-m-d H:i:s'));
    }

    /**
     * Query builder, not Eloquent, so updated_at is left alone.
     *
     * @param  callable(string): string  $convert
     */
    private function convert(callable $convert): void
    {
        DB::table('version_dates')->orderBy('id')->each(function (object $row) use ($convert): void {
            DB::table('version_dates')->where('id', $row->id)->update([
                'start_at' => $convert((string) $row->start_at),
                'end_at' => $row->end_at !== null ? $convert((string) $row->end_at) : null,
            ]);
        });
    }

    /**
     * A wall-clock value inside the spring-forward gap doesn't exist, and one
     * inside the fall-back hour exists twice — either way its UTC value is a
     * guess, so stop and list them for a manual decision instead.
     */
    private function assertNoDstEdgeCases(): void
    {
        $problems = [];

        foreach (DB::table('version_dates')->orderBy('id')->get() as $row) {
            foreach (['start_at', 'end_at'] as $column) {
                if ($row->{$column} === null) {
                    continue;
                }

                $wallClock = Carbon::parse((string) $row->{$column})->format('Y-m-d H:i:s');
                $parsed = Carbon::parse($wallClock, self::WALL_CLOCK_TIMEZONE);
                $sameWallClockAnHourAway = fn (int $hours): bool => $parsed->copy()->utc()->addHours($hours)
                    ->timezone(self::WALL_CLOCK_TIMEZONE)->format('Y-m-d H:i:s') === $wallClock;

                if ($parsed->format('Y-m-d H:i:s') !== $wallClock || $sameWallClockAnHourAway(1) || $sameWallClockAnHourAway(-1)) {
                    $problems[] = "version_dates.id {$row->id} {$column} = {$wallClock}";
                }
            }
        }

        if ($problems !== []) {
            throw new RuntimeException("Ambiguous or non-existent Eastern times; fix these before converting:\n".implode("\n", $problems));
        }
    }
};
