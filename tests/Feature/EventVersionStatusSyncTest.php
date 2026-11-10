<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Livewire\Events\Index;
use App\Models\Event;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('activating a Version promotes its Sandbox Event to Active', function () {
    $event = Event::factory()->create(['status' => EventStatus::Sandbox]);
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => EventStatus::Sandbox]);

    $version->update(['status' => EventStatus::Active->value]);

    expect($event->fresh()->getRawOriginal('status'))->toBe(EventStatus::Active->value);
});

test('activating a Version promotes an Inactive Event to Active', function () {
    $event = Event::factory()->create(['status' => EventStatus::Inactive]);
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => EventStatus::Sandbox]);

    $version->update(['status' => EventStatus::Active->value]);

    expect($event->fresh()->getRawOriginal('status'))->toBe(EventStatus::Active->value);
});

test('creating an Active Version promotes its Event to Active', function () {
    $event = Event::factory()->create(['status' => EventStatus::Sandbox]);

    Version::factory()->active()->create(['event_id' => $event->id]);

    expect($event->fresh()->getRawOriginal('status'))->toBe(EventStatus::Active->value);
});

test('a non-Active Version status change leaves the Event alone', function () {
    $event = Event::factory()->create(['status' => EventStatus::Sandbox]);
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => EventStatus::Sandbox]);

    $version->update(['status' => EventStatus::Inactive->value]);

    expect($event->fresh()->getRawOriginal('status'))->toBe(EventStatus::Sandbox->value);
});

test('Founder cannot move an Event below Active while it has an Active Version', function () {
    $founder = makeFounder();
    $event = Event::factory()->active()->create();
    Version::factory()->active()->create(['event_id' => $event->id, 'name' => 'Spring 2027']);

    Livewire::actingAs($founder)
        ->test(Index::class)
        ->call('edit', $event->id)
        ->set('edit_status', EventStatus::Sandbox->value)
        ->call('save')
        ->assertHasErrors('edit_status')
        ->assertSee('Spring 2027');

    expect($event->fresh()->getRawOriginal('status'))->toBe(EventStatus::Active->value);
});

test('Founder can move an Event below Active once no Version is Active', function () {
    $founder = makeFounder();
    $event = Event::factory()->active()->create();
    Version::factory()->create(['event_id' => $event->id, 'status' => EventStatus::Closed]);

    Livewire::actingAs($founder)
        ->test(Index::class)
        ->call('edit', $event->id)
        ->set('edit_status', EventStatus::Inactive->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($event->fresh()->getRawOriginal('status'))->toBe(EventStatus::Inactive->value);
});
