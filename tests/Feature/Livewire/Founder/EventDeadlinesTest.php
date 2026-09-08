<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\VersionDateType;
use App\Livewire\Founder\EventDeadlines;
use App\Models\Event;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeEventDeadlinesFounder(): User
{
    // rick@mfrholdings.com may already exist from seeded data — reuse it rather
    // than colliding with the unique email constraint.
    return User::where('email', 'rick@mfrholdings.com')->first()
        ?? User::factory()->create(['email' => 'rick@mfrholdings.com']);
}

test('a non-founder cannot view the event deadlines page', function () {
    $user = User::factory()->create();

    actingAs($user)->get(route('founder.event-deadlines'))->assertNotFound();
});

test('the founder can view the event deadlines page', function () {
    $founder = makeEventDeadlinesFounder();

    actingAs($founder)->get(route('founder.event-deadlines'))->assertOk()->assertSeeText('Event Deadlines');
});

test('only active and sandbox versions are shown, not inactive or closed', function () {
    $founder = makeEventDeadlinesFounder();
    $event = Event::factory()->create(['name' => 'All State Choir']);
    Version::factory()->for($event)->create(['name' => 'Active Version', 'status' => EventStatus::Active]);
    Version::factory()->for($event)->create(['name' => 'Sandbox Version', 'status' => EventStatus::Sandbox]);
    Version::factory()->for($event)->create(['name' => 'Inactive Version', 'status' => EventStatus::Inactive]);
    Version::factory()->for($event)->create(['name' => 'Closed Version', 'status' => EventStatus::Closed]);

    Livewire::actingAs($founder)
        ->test(EventDeadlines::class)
        ->assertSee('All State Choir')
        ->assertSee('Active Version')
        ->assertSee('Sandbox Version')
        ->assertDontSee('Inactive Version')
        ->assertDontSee('Closed Version');
});

test('an event with no active or sandbox versions is not shown at all', function () {
    $founder = makeEventDeadlinesFounder();
    $event = Event::factory()->create(['name' => 'Retired Festival']);
    Version::factory()->for($event)->create(['status' => EventStatus::Closed]);

    Livewire::actingAs($founder)
        ->test(EventDeadlines::class)
        ->assertDontSee('Retired Festival');
});

test('the next upcoming deadline is listed before a past deadline', function () {
    $founder = makeEventDeadlinesFounder();
    $event = Event::factory()->create(['name' => 'Spring Showcase']);
    $version = Version::factory()->for($event)->create(['status' => EventStatus::Active]);

    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => VersionDateType::Teacher,
        'start_at' => now()->subDays(10),
    ]);
    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => VersionDateType::PostmarkDeadline,
        'start_at' => now()->addDays(5),
    ]);

    $html = Livewire::actingAs($founder)
        ->test(EventDeadlines::class)
        ->html();

    expect(strpos($html, 'Postmark Deadline'))->toBeLessThan(strpos($html, 'Teacher Access'));
});
