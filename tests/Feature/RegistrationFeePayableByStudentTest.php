<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\VersionDateType;
use App\Models\Version;
use App\Models\VersionDate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('registrationFeePayableByStudent is false once the Version is closed, same as registrationFeePayable', function () {
    $version = Version::factory()->create(['status' => EventStatus::Closed]);

    expect($version->registrationFeePayableByStudent())->toBeFalse();
});

test('registrationFeePayableByStudent is true when the Version has no Adjudication date configured at all', function () {
    $version = Version::factory()->create(['status' => EventStatus::Active]);

    expect($version->registrationFeePayableByStudent())->toBeTrue();
});

test('registrationFeePayableByStudent is true while the Adjudication window has not started yet', function () {
    $version = Version::factory()->create(['status' => EventStatus::Active]);
    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => VersionDateType::Adjudication,
        'start_at' => now()->addWeek(),
    ]);

    expect($version->registrationFeePayableByStudent())->toBeTrue();
});

test('registrationFeePayableByStudent is false once the Adjudication window has started — a tighter cutoff than registrationFeePayable', function () {
    $version = Version::factory()->create(['status' => EventStatus::Active]);
    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => VersionDateType::Adjudication,
        'start_at' => now()->subDay(),
    ]);

    expect($version->registrationFeePayableByStudent())->toBeFalse();
    // registrationFeePayable() (teacher-facing) stays "any time before
    // Closed" — deliberately unchanged, studentfolder-module.md §9 item 2.
    expect($version->registrationFeePayable())->toBeTrue();
});
