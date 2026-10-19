<?php

declare(strict_types=1);

use App\Enums\VersionDateType;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * RefreshDatabase already ran this migration against an empty table;
 * re-running up()/down() here exercises it against seeded wall-clock rows.
 */
function runConvertVersionDatesToUtcMigration(string $direction): void
{
    $migration = require database_path('migrations/2026_10_19_000001_convert_version_dates_to_utc.php');
    $migration->{$direction}();
}

function insertWallClockVersionDate(Version $version, VersionDateType $type, string $start, ?string $end): int
{
    return DB::table('version_dates')->insertGetId([
        'version_id' => $version->id,
        'date_type' => $type->value,
        'start_at' => $start,
        'end_at' => $end,
        'created_at' => '2026-09-27 21:53:01',
        'updated_at' => '2026-09-27 21:53:01',
    ]);
}

function versionDateColumn(int $id, string $column): ?string
{
    return DB::table('version_dates')->where('id', $id)->value($column);
}

test('up converts Eastern wall-clock values to UTC with each value\'s own DST offset, leaving null end_at and updated_at alone', function () {
    $version = Version::factory()->create();
    $candidate = insertWallClockVersionDate($version, VersionDateType::Candidate, '2026-10-05 00:00:00', '2026-10-26 17:00:00');
    $postmark = insertWallClockVersionDate($version, VersionDateType::PostmarkDeadline, '2026-11-02 17:00:00', null);
    $adjudication = insertWallClockVersionDate($version, VersionDateType::Adjudication, '2027-01-15 09:00:00', '2027-03-20 17:00:00');

    runConvertVersionDatesToUtcMigration('up');

    expect(versionDateColumn($candidate, 'start_at'))->toBe('2026-10-05 04:00:00') // EDT
        ->and(versionDateColumn($candidate, 'end_at'))->toBe('2026-10-26 21:00:00')
        ->and(versionDateColumn($postmark, 'start_at'))->toBe('2026-11-02 22:00:00') // EST — DST ended Nov 1
        ->and(versionDateColumn($postmark, 'end_at'))->toBeNull()
        ->and(versionDateColumn($adjudication, 'start_at'))->toBe('2027-01-15 14:00:00') // EST
        ->and(versionDateColumn($adjudication, 'end_at'))->toBe('2027-03-20 21:00:00') // EDT — DST began Mar 14
        ->and(versionDateColumn($candidate, 'updated_at'))->toBe('2026-09-27 21:53:01');
});

test('down converts UTC back to the original Eastern wall-clock values', function () {
    $id = insertWallClockVersionDate(Version::factory()->create(), VersionDateType::Candidate, '2026-10-05 00:00:00', '2026-10-26 17:00:00');

    runConvertVersionDatesToUtcMigration('up');
    runConvertVersionDatesToUtcMigration('down');

    expect(versionDateColumn($id, 'start_at'))->toBe('2026-10-05 00:00:00')
        ->and(versionDateColumn($id, 'end_at'))->toBe('2026-10-26 17:00:00');
});

test('up refuses to convert anything when a value falls in a DST gap or overlap, and lists them', function () {
    $version = Version::factory()->create();
    $fine = insertWallClockVersionDate($version, VersionDateType::Candidate, '2026-10-05 00:00:00', null);
    $overlap = insertWallClockVersionDate($version, VersionDateType::Teacher, '2026-11-01 01:30:00', null);
    $gap = insertWallClockVersionDate($version, VersionDateType::Adjudication, '2027-03-01 09:00:00', '2027-03-14 02:30:00');

    expect(fn () => runConvertVersionDatesToUtcMigration('up'))->toThrow(
        RuntimeException::class,
        "version_dates.id {$overlap} start_at = 2026-11-01 01:30:00\nversion_dates.id {$gap} end_at = 2027-03-14 02:30:00",
    );

    expect(versionDateColumn($fine, 'start_at'))->toBe('2026-10-05 00:00:00');
});
