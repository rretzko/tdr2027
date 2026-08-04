<?php

declare(strict_types=1);

use App\Livewire\Events\Reports\AdjudicationBackup;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

test('mount aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    Livewire::actingAs($user)
        ->test(AdjudicationBackup::class, ['version' => $version])
        ->assertStatus(403);
});

test('lists the Version\'s rooms and shows the placeholder notice', function () {
    $founder = makeFounder();
    $version = Version::factory()->create();
    VersionRoom::create(['version_id' => $version->id, 'name' => 'Soprano I', 'order_by' => 1]);

    Livewire::actingAs($founder)
        ->test(AdjudicationBackup::class, ['version' => $version])
        ->assertOk()
        ->assertSee('Soprano I')
        ->assertSee('No in-person audition is scheduled');
});

test('export returns a PDF for the paper type', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();

    get(route('events.versions.reports.adjudication-backup.export', ['version' => $version, 'type' => 'paper']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('export returns a PDF for the checklist type', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();

    get(route('events.versions.reports.adjudication-backup.export', ['version' => $version, 'type' => 'checklist']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('export returns a CSV for the csv type, listing rooms with a placeholder note', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    VersionRoom::create(['version_id' => $version->id, 'name' => 'Alto I', 'order_by' => 1]);

    $response = get(route('events.versions.reports.adjudication-backup.export', ['version' => $version, 'type' => 'csv']));

    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    expect($response->streamedContent())->toContain('Alto I');
});

test('export rejects an invalid type', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();

    get(route('events.versions.reports.adjudication-backup.export', ['version' => $version, 'type' => 'bogus']))
        ->assertNotFound();
});

test('export aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    actingAs($user);

    get(route('events.versions.reports.adjudication-backup.export', ['version' => $version, 'type' => 'paper']))
        ->assertForbidden();
});
