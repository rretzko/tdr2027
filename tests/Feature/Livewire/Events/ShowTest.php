<?php

declare(strict_types=1);

use App\Livewire\Events\Show;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\RoomJudge;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Services\VersionRoleAssignmentService;
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

test('the Take a tour button auto-starts for a user who has never taken it', function () {
    $founder = makeFounder();
    $event = Event::factory()->create();

    Livewire::actingAs($founder)
        ->test(Show::class, ['event' => $event])
        ->assertSee('Take a tour')
        ->assertSeeHtml('data-auto-start="1"');
});

test('the Take a tour button does not auto-start once the tour has already been taken', function () {
    $founder = makeFounder();
    $founder->update(['dismissed_event_orientation_at' => now()]);
    $event = Event::factory()->create();

    Livewire::actingAs($founder)
        ->test(Show::class, ['event' => $event])
        ->assertSeeHtml('data-auto-start="0"');
});

test('dismissOrientation persists the dismissal for the acting user', function () {
    $founder = makeFounder();
    $event = Event::factory()->create();

    Livewire::actingAs($founder)
        ->test(Show::class, ['event' => $event])
        ->call('dismissOrientation');

    expect($founder->fresh()->dismissed_event_orientation_at)->not->toBeNull();
});

test('the tour anchors for both tabs render for a Founder with a Version and an Ensemble', function () {
    $founder = makeFounder();
    $event = Event::factory()->create();
    Version::factory()->create(['event_id' => $event->id]);
    Ensemble::factory()->create(['event_id' => $event->id]);

    $component = Livewire::actingAs($founder)->test(Show::class, ['event' => $event]);

    foreach ([
        'id="tour-event-badges"',
        'id="tour-event-tabs"',
        'id="tour-tab-versions"',
        'id="tour-tab-ensembles"',
        'id="tour-add-version"',
        'id="tour-version-list-desktop"',
        'id="tour-version-list-mobile"',
        'id="tour-version-actions-desktop"',
        'id="tour-version-actions-mobile"',
        'id="tour-add-ensemble"',
        'id="tour-ensemble-card"',
        'id="tour-ensemble-grades"',
        'id="tour-ensemble-voiceparts"',
    ] as $needle) {
        $component->assertSeeHtml($needle);
    }
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

test('a Registration Manager gets the Web Registration button (for its Voice Part Audit tab)', function () {
    $user = makeShowTestUser();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    grantVersionRole($user, $version, 'Registration Manager');

    Livewire::actingAs($user)
        ->test(Show::class, ['event' => $event])
        ->assertViewHas('versionWebRegistrationAccess', fn ($access): bool => ($access[$version->id] ?? false) === true)
        ->assertSee(route('events.versions.web-registration', $version));
});

test('a RoomJudge-only holder does not get the Web Registration button', function () {
    $user = makeShowTestUser();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    RoomJudge::factory()->create(['version_id' => $version->id, 'user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(Show::class, ['event' => $event])
        ->assertViewHas('versionWebRegistrationAccess', fn ($access): bool => ($access[$version->id] ?? false) === false);
});

test('mount allows a RoomJudge-only holder to view the Event, though they hold no Spatie role at all', function () {
    $user = makeShowTestUser();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    RoomJudge::factory()->create(['version_id' => $version->id, 'user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(Show::class, ['event' => $event])
        ->assertOk()
        ->assertViewHas('canManageEvent', false);
});

test('a Registration Manager sees Rooms and Co-Registration Managers but not Configure/Invitations/Pitch Files for their Version', function () {
    $user = makeShowTestUser();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    grantVersionRole($user, $version, 'Registration Manager');

    Livewire::actingAs($user)
        ->test(Show::class, ['event' => $event])
        ->assertOk()
        ->assertSeeText('Rooms')
        ->assertSeeText('Scoring Rubric')
        ->assertSeeText('Co-Registration Managers')
        ->assertDontSeeText('Configure')
        ->assertDontSeeText('Invitations')
        ->assertDontSeeText('Pitch Files');
});

test('a Co-Registration Manager sees Rooms but not Configure/Invitations/Pitch Files/Co-Registration Managers for their Version', function () {
    $user = makeShowTestUser();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    grantVersionRole($user, $version, 'Co-Registration Manager');

    Livewire::actingAs($user)
        ->test(Show::class, ['event' => $event])
        ->assertOk()
        ->assertSeeText('Rooms')
        ->assertSeeText('Scoring Rubric')
        ->assertDontSeeText('Configure')
        ->assertDontSeeText('Invitations')
        ->assertDontSeeText('Pitch Files')
        ->assertDontSeeText('Co-Registration Managers');
});

test('an Event Manager sees Configure/Invitations/Pitch Files for a Version', function () {
    $user = makeShowTestUser();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(Show::class, ['event' => $event])
        ->assertOk()
        ->assertSeeText('Configure')
        ->assertSeeText('Invitations')
        ->assertSeeText('Pitch Files')
        ->assertSeeText('Co-Registration Managers');
});

test('createVersion clones the latest sibling Version for an Event Manager', function () {
    $user = makeShowTestUser();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'audition_timeslot' => 20]);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(Show::class, ['event' => $event])
        ->set('new_name', 'Second Version')
        ->set('new_senior_class_of', '2028')
        ->call('createVersion')
        ->assertHasNoErrors();

    $clone = Version::where('event_id', $event->id)->where('name', 'Second Version')->first();

    expect($clone)->not->toBeNull();
    expect($clone->audition_timeslot)->toBe(20);
});

test("createVersion gives the clone the latest Version's Event Managers and the creator, and no other roles", function () {
    $event = Event::factory()->create();
    $older = Version::factory()->create(['event_id' => $event->id, 'senior_class_of' => 2026]);
    $latest = Version::factory()->create(['event_id' => $event->id, 'senior_class_of' => 2027]);

    // Holds the role only on an older Version, which still grants Event-wide access.
    $creator = makeShowTestUser();
    grantVersionRole($creator, $older, 'Event Manager');

    $latestManager = makeShowTestUser();
    grantVersionRole($latestManager, $latest, 'Event Manager');
    $formerManager = makeShowTestUser();
    grantVersionRole($formerManager, $older, 'Event Manager');
    grantVersionRole(makeShowTestUser(), $latest, 'Registration Manager');

    Livewire::actingAs($creator)
        ->test(Show::class, ['event' => $event])
        ->set('new_name', 'Cloned Version')
        ->set('new_senior_class_of', '2028')
        ->call('createVersion')
        ->assertHasNoErrors();

    $clone = Version::where('event_id', $event->id)->where('name', 'Cloned Version')->firstOrFail();
    $assignments = app(VersionRoleAssignmentService::class)->assignmentsForVersion($clone);

    expect($assignments['Event Manager']->pluck('id')->sort()->values()->all())
        ->toBe(collect([$creator->id, $latestManager->id])->sort()->values()->all());
    expect($assignments['Registration Manager']->isEmpty())->toBeTrue();
    expect(VersionInvitation::where('version_id', $clone->id)->count())->toBe(2);
});

test('createVersion assigns a creator who already manages the latest Version only once', function () {
    $creator = makeShowTestUser();
    $event = Event::factory()->create();
    $latest = Version::factory()->create(['event_id' => $event->id]);
    grantVersionRole($creator, $latest, 'Event Manager');

    Livewire::actingAs($creator)
        ->test(Show::class, ['event' => $event])
        ->set('new_name', 'Cloned Version')
        ->set('new_senior_class_of', '2028')
        ->call('createVersion')
        ->assertHasNoErrors();

    $clone = Version::where('event_id', $event->id)->where('name', 'Cloned Version')->firstOrFail();
    $service = app(VersionRoleAssignmentService::class);

    expect($service->assignmentsForVersion($clone)['Event Manager']->pluck('id')->all())->toBe([$creator->id]);
    expect($service->assignmentsForVersion($latest)['Event Manager']->pluck('id')->all())->toBe([$creator->id]);
});

test('createVersion by the Founder copies Event Managers without adding the Founder', function () {
    $founder = makeFounder();
    $manager = makeShowTestUser();
    $event = Event::factory()->create();
    $latest = Version::factory()->create(['event_id' => $event->id]);
    grantVersionRole($manager, $latest, 'Event Manager');

    Livewire::actingAs($founder)
        ->test(Show::class, ['event' => $event])
        ->set('new_name', 'Cloned Version')
        ->set('new_senior_class_of', '2028')
        ->call('createVersion')
        ->assertHasNoErrors();

    $clone = Version::where('event_id', $event->id)->where('name', 'Cloned Version')->firstOrFail();

    expect(app(VersionRoleAssignmentService::class)->assignmentsForVersion($clone)['Event Manager']->pluck('id')->all())
        ->toBe([$manager->id]);
});

test('inheritEventManagers assigns just the creator when there is no source Version', function () {
    $creator = makeShowTestUser();
    $version = Version::factory()->create();
    $service = app(VersionRoleAssignmentService::class);

    $service->inheritEventManagers($creator, $version, null);

    expect($service->assignmentsForVersion($version)['Event Manager']->pluck('id')->all())->toBe([$creator->id]);
});

test('createVersion falls back to defaults when the Event has no existing Version', function () {
    $founder = makeFounder();
    $event = Event::factory()->create();

    Livewire::actingAs($founder)
        ->test(Show::class, ['event' => $event])
        ->set('new_name', 'First Version')
        ->set('new_senior_class_of', '2028')
        ->call('createVersion')
        ->assertHasNoErrors();

    $version = Version::where('event_id', $event->id)->where('name', 'First Version')->first();

    expect($version)->not->toBeNull();
    expect($version->audition_timeslot)->toBe(0);
    // Default PDF application → mailed, same rule as the mail_required backfill.
    expect($version->mail_required)->toBeTrue();
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
