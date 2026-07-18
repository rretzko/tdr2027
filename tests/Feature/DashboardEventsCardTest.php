<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Organization;
use App\Models\Pivots\TeacherSupervisor;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('the dashboard shows a no-open-events message when there are none', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    // The managed-events card content (vs. the "start a new event" CTA) only
    // renders once the teacher holds a version-scoped role somewhere.
    $organization = Organization::factory()->create();
    TeacherSupervisor::create(['organization_id' => $organization->id, 'teacher_id' => $teacher->id]);
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $version = Version::factory()->create(['event_id' => $event->id]);
    grantVersionRole($user, $version, 'Event Manager');

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Events')
        ->assertSeeText('No open events.');
});

test('the dashboard lists open events from linked organizations', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    $organization = Organization::factory()->create();
    TeacherSupervisor::create(['organization_id' => $organization->id, 'teacher_id' => $teacher->id]);
    $activeEvent = Event::factory()->active()->create(['organization_id' => $organization->id, 'name' => 'Spring Showcase']);
    Event::factory()->create(['organization_id' => $organization->id, 'name' => 'Closed Event']);

    $version = Version::factory()->create(['event_id' => $activeEvent->id]);
    grantVersionRole($user, $version, 'Event Manager');

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Spring Showcase')
        ->assertDontSeeText('Closed Event');
});

test('the dashboard does not show events from organizations the teacher is not linked to', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    // Grants access to the managed-events card without linking the teacher
    // to the unrelated organization created below.
    $ownOrganization = Organization::factory()->create();
    $ownEvent = Event::factory()->create(['organization_id' => $ownOrganization->id]);
    $ownVersion = Version::factory()->create(['event_id' => $ownEvent->id]);
    grantVersionRole($user, $ownVersion, 'Event Manager');

    $organization = Organization::factory()->create();
    Event::factory()->active()->create(['organization_id' => $organization->id, 'name' => 'Unrelated Event']);

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSeeText('Unrelated Event')
        ->assertSeeText('No open events.');
});
