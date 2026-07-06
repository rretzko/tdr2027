<?php

declare(strict_types=1);

use App\Livewire\Events\Show;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeShowTestUser(): User
{
    $user = User::factory()->create();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    return $user;
}

test('mount allows Founder to view a brand-new Event with zero Versions', function () {
    $founder = makeFounder();
    $event = Event::factory()->create();

    Livewire::actingAs($founder)
        ->test(Show::class, ['event' => $event])
        ->assertOk();
});

test('mount aborts with 403 for a user holding no version-scoped role on any Version of the Event', function () {
    $user = makeShowTestUser();
    $event = Event::factory()->create();
    Version::factory()->create(['event_id' => $event->id]);

    Livewire::actingAs($user)
        ->test(Show::class, ['event' => $event])
        ->assertStatus(403);
});

test('mount allows a user holding Event Manager on any sibling Version of the Event', function () {
    $user = makeShowTestUser();
    $event = Event::factory()->create();
    $versionA = Version::factory()->create(['event_id' => $event->id]);
    Version::factory()->create(['event_id' => $event->id]);
    grantVersionRole($user, $versionA, 'Event Manager');

    Livewire::actingAs($user)
        ->test(Show::class, ['event' => $event])
        ->assertOk();
});

test('mount allows a Registration-Manager-only holder to view the Event', function () {
    $user = makeShowTestUser();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    grantVersionRole($user, $version, 'Registration Manager');

    Livewire::actingAs($user)
        ->test(Show::class, ['event' => $event])
        ->assertOk()
        ->assertViewHas('canManageEvent', false);
});

test('createVersion succeeds for an Event Manager', function () {
    $user = makeShowTestUser();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(Show::class, ['event' => $event])
        ->set('new_name', 'Second Version')
        ->set('new_senior_class_of', '2028')
        ->call('createVersion')
        ->assertHasNoErrors();

    expect(Version::where('event_id', $event->id)->where('name', 'Second Version')->exists())->toBeTrue();
});

test('createVersion aborts with 403 for a Registration-Manager-only holder', function () {
    $user = makeShowTestUser();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    grantVersionRole($user, $version, 'Registration Manager');

    Livewire::actingAs($user)
        ->test(Show::class, ['event' => $event])
        ->set('new_name', 'Should Not Exist')
        ->set('new_senior_class_of', '2028')
        ->call('createVersion')
        ->assertStatus(403);

    expect(Version::where('event_id', $event->id)->where('name', 'Should Not Exist')->exists())->toBeFalse();
});

test('saveEnsemble aborts with 403 for a Registration-Manager-only holder', function () {
    $user = makeShowTestUser();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    grantVersionRole($user, $version, 'Registration Manager');

    Livewire::actingAs($user)
        ->test(Show::class, ['event' => $event])
        ->set('ens_name', 'Should Not Exist')
        ->call('saveEnsemble')
        ->assertStatus(403);

    expect(Ensemble::where('event_id', $event->id)->where('name', 'Should Not Exist')->exists())->toBeFalse();
});

test('saveEnsemble succeeds for an Event Manager', function () {
    $user = makeShowTestUser();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(Show::class, ['event' => $event])
        ->set('ens_name', 'Mixed Chorus')
        ->call('saveEnsemble')
        ->assertHasNoErrors();

    expect(Ensemble::where('event_id', $event->id)->where('name', 'Mixed Chorus')->exists())->toBeTrue();
});
