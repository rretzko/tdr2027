<?php

declare(strict_types=1);

use App\Livewire\Events\Index;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeIndexTestUser(): User
{
    $user = User::factory()->create();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    return $user;
}

test('Founder sees every Event regardless of role assignments', function () {
    $founder = makeFounder();
    $eventA = Event::factory()->create(['name' => 'Event A']);
    $eventB = Event::factory()->create(['name' => 'Event B']);

    Livewire::actingAs($founder)
        ->test(Index::class)
        ->assertSee('Event A')
        ->assertSee('Event B');
});

test('a non-Founder only sees Events where they hold a version-scoped role', function () {
    $user = makeIndexTestUser();
    $visibleEvent = Event::factory()->create(['name' => 'Visible Event']);
    $hiddenEvent = Event::factory()->create(['name' => 'Hidden Event']);
    $visibleVersion = Version::factory()->create(['event_id' => $visibleEvent->id]);
    Version::factory()->create(['event_id' => $hiddenEvent->id]);

    grantVersionRole($user, $visibleVersion, 'Tab Room Manager');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Visible Event')
        ->assertDontSee('Hidden Event');
});

test('a non-Founder with no version-scoped roles sees no Events', function () {
    $user = makeIndexTestUser();
    Event::factory()->create(['name' => 'Some Event']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertDontSee('Some Event');
});

test('add aborts with 403 for a non-Founder', function () {
    $user = makeIndexTestUser();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->assertStatus(403);
});

test('edit aborts with 403 for a non-Founder', function () {
    $user = makeIndexTestUser();
    $event = Event::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $event->id)
        ->assertStatus(403);
});

test('save aborts with 403 for a non-Founder', function () {
    $user = makeIndexTestUser();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('edit_name', 'Should Not Exist')
        ->call('save')
        ->assertStatus(403);

    expect(Event::where('name', 'Should Not Exist')->exists())->toBeFalse();
});

test('add and save succeed for Founder', function () {
    $founder = makeFounder();
    $organization = Organization::factory()->create();

    Livewire::actingAs($founder)
        ->test(Index::class)
        ->call('add')
        ->set('edit_name', 'Brand New Event')
        ->set('edit_organization_id', (string) $organization->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Event::where('name', 'Brand New Event')->exists())->toBeTrue();
});

test('the Add Event button is hidden for a non-Founder and visible for Founder', function () {
    $user = makeIndexTestUser();
    $founder = makeFounder();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertDontSee('Add event');

    Livewire::actingAs($founder)
        ->test(Index::class)
        ->assertSee('Add event');
});
