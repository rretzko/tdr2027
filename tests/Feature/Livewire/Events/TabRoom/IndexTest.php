<?php

declare(strict_types=1);

use App\Livewire\Events\TabRoom\Index;
use App\Models\Event;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('mount succeeds for a Tab Room Manager and links to the built sub-modules', function () {
    $manager = User::factory()->create();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    Livewire::actingAs($manager)
        ->test(Index::class, ['version' => $version])
        ->assertOk()
        ->assertSee('Add/Edit Scores')
        ->assertSee('Adjudication Tracking')
        ->assertSee('Coming soon');
});

test('mount aborts with 403 for a user with no Tab Room Manager role', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);

    Livewire::actingAs($user)
        ->test(Index::class, ['version' => $version])
        ->assertStatus(403);
});
