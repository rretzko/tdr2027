<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\User;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use App\Services\VersionRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

test('assignableRoleNames returns exactly the 6 version-scoped roles', function () {
    $service = app(VersionRoleAssignmentService::class);

    expect($service->assignableRoleNames())->toBe([
        'Event Manager',
        'Registration Manager',
        'Co-Registration Manager',
        'Web Registration Manager',
        'Tab Room Manager',
        'Rehearsal Manager',
    ]);
});

test('canManageEvent is true for Founder even with no role assignments', function () {
    $service = app(VersionRoleAssignmentService::class);
    $founder = makeFounder();
    $event = Event::factory()->create();

    expect($service->canManageEvent($founder, $event))->toBeTrue();
});

test('canManageEvent is false for a user with no roles', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();

    expect($service->canManageEvent($user, $event))->toBeFalse();
});

test('canManageEvent is true when the user holds Event Manager on any Version of the Event', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $versionA = Version::factory()->create(['event_id' => $event->id]);
    Version::factory()->create(['event_id' => $event->id]);

    grantVersionRole($user, $versionA, 'Event Manager');

    expect($service->canManageEvent($user, $event))->toBeTrue();
});

test('canManageEvent is false when the user holds Event Manager on a different Event entirely', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $otherEvent = Event::factory()->create();
    $otherVersion = Version::factory()->create(['event_id' => $otherEvent->id]);

    grantVersionRole($user, $otherVersion, 'Event Manager');

    expect($service->canManageEvent($user, $event))->toBeFalse();
});

test('canManageEvent is false when the user holds a different version-scoped role (not Event Manager)', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);

    grantVersionRole($user, $version, 'Registration Manager');

    expect($service->canManageEvent($user, $event))->toBeFalse();
});

test('canViewEvent is true for any of the 6 version-scoped roles, not just Event Manager', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);

    grantVersionRole($user, $version, 'Tab Room Manager');

    expect($service->canViewEvent($user, $event))->toBeTrue();
    expect($service->canManageEvent($user, $event))->toBeFalse();
});

test('canViewEvent is false for a user with no version-scoped role on the Event', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    Version::factory()->create(['event_id' => $event->id]);

    expect($service->canViewEvent($user, $event))->toBeFalse();
});

test('canAccessVersion is true when the user holds any of the 6 roles scoped to that specific Version', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $version = Version::factory()->create();

    grantVersionRole($user, $version, 'Rehearsal Manager');

    expect($service->canAccessVersion($user, $version))->toBeTrue();
});

test('canAccessVersion is false when the role is held on a sibling Version, not this one, and the role is not Event Manager', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $versionA = Version::factory()->create(['event_id' => $event->id]);
    $versionB = Version::factory()->create(['event_id' => $event->id]);

    grantVersionRole($user, $versionA, 'Rehearsal Manager');

    expect($service->canAccessVersion($user, $versionB))->toBeFalse();
});

test('canAccessVersion is true via the Event Manager sibling-version fallback', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $versionA = Version::factory()->create(['event_id' => $event->id]);
    $versionB = Version::factory()->create(['event_id' => $event->id]);

    grantVersionRole($user, $versionA, 'Event Manager');

    expect($service->canAccessVersion($user, $versionB))->toBeTrue();
});

test('canAccessVersion is true for Founder regardless of any role assignment', function () {
    $service = app(VersionRoleAssignmentService::class);
    $founder = makeFounder();
    $version = Version::factory()->create();

    expect($service->canAccessVersion($founder, $version))->toBeTrue();
});

test('canManageVersionRoles is true for Event Manager (directly or via sibling version) and false for other roles', function () {
    $service = app(VersionRoleAssignmentService::class);
    $event = Event::factory()->create();
    $versionA = Version::factory()->create(['event_id' => $event->id]);
    $versionB = Version::factory()->create(['event_id' => $event->id]);

    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $versionA, 'Event Manager');

    $registrationManager = User::factory()->create();
    grantVersionRole($registrationManager, $versionB, 'Registration Manager');

    expect($service->canManageVersionRoles($eventManager, $versionB))->toBeTrue();
    expect($service->canManageVersionRoles($registrationManager, $versionB))->toBeFalse();
});

test('eventIdsVisibleTo returns only Events where the user holds a version-scoped role', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $visibleEvent = Event::factory()->create();
    $hiddenEvent = Event::factory()->create();
    $visibleVersion = Version::factory()->create(['event_id' => $visibleEvent->id]);
    Version::factory()->create(['event_id' => $hiddenEvent->id]);

    grantVersionRole($user, $visibleVersion, 'Co-Registration Manager');

    expect($service->eventIdsVisibleTo($user))->toBe([$visibleEvent->id]);
});

test('eventIdsVisibleTo returns an empty array for a user with no version-scoped roles', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    Event::factory()->create();

    expect($service->eventIdsVisibleTo($user))->toBe([]);
});

test('assignRole grants the role under the target Version context and assignmentsForVersion reflects it', function () {
    $service = app(VersionRoleAssignmentService::class);
    $event = Event::factory()->create();
    $versionA = Version::factory()->create(['event_id' => $event->id]);
    $versionB = Version::factory()->create(['event_id' => $event->id]);

    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $versionA, 'Event Manager');

    $newHire = User::factory()->create();
    $service->assignRole($eventManager, $versionB, $newHire, 'Web Registration Manager');

    $assignments = $service->assignmentsForVersion($versionB);

    expect($assignments->get('Web Registration Manager')->pluck('id'))->toContain($newHire->id);
    expect($assignments->get('Tab Room Manager'))->toBeEmpty();
});

test('assignRole aborts with 403 when the acting user cannot manage the Version', function () {
    $service = app(VersionRoleAssignmentService::class);
    $version = Version::factory()->create();
    $unauthorizedActor = User::factory()->create();
    $targetUser = User::factory()->create();

    expect(fn () => $service->assignRole($unauthorizedActor, $version, $targetUser, 'Event Manager'))
        ->toThrow(HttpException::class);
});

test('assignRole aborts with 400 when the role name is not one of the 6 version-scoped roles', function () {
    $service = app(VersionRoleAssignmentService::class);
    $founder = makeFounder();
    $version = Version::factory()->create();
    $targetUser = User::factory()->create();

    expect(fn () => $service->assignRole($founder, $version, $targetUser, 'Teacher'))
        ->toThrow(HttpException::class);
});

test('revokeRole removes the assignment and requires the acting user to manage the Version', function () {
    $service = app(VersionRoleAssignmentService::class);
    $version = Version::factory()->create();
    $founder = makeFounder();
    $targetUser = User::factory()->create();

    $service->assignRole($founder, $version, $targetUser, 'Rehearsal Manager');
    expect($service->assignmentsForVersion($version)->get('Rehearsal Manager')->pluck('id'))->toContain($targetUser->id);

    $service->revokeRole($founder, $version, $targetUser, 'Rehearsal Manager');
    expect($service->assignmentsForVersion($version)->get('Rehearsal Manager')->pluck('id'))->not->toContain($targetUser->id);
});

test('revokeRole aborts with 403 when the acting user cannot manage the Version', function () {
    $service = app(VersionRoleAssignmentService::class);
    $founder = makeFounder();
    $version = Version::factory()->create();
    $targetUser = User::factory()->create();
    $service->assignRole($founder, $version, $targetUser, 'Rehearsal Manager');

    $unauthorizedActor = User::factory()->create();

    expect(fn () => $service->revokeRole($unauthorizedActor, $version, $targetUser, 'Rehearsal Manager'))
        ->toThrow(HttpException::class);
});

test('a global role assignment (e.g. Teacher) does not make eventIdsVisibleTo or canViewEvent true', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    Version::factory()->create(['event_id' => $event->id]);

    app(VersionRoleService::class)->withGlobal(fn () => $user->assignRole('Teacher'));

    expect($service->canViewEvent($user, $event))->toBeFalse();
    expect($service->eventIdsVisibleTo($user))->toBe([]);
});
