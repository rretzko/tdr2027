<?php

declare(strict_types=1);

use App\Models\CoRegistrationManagerCounty;
use App\Models\County;
use App\Models\Event;
use App\Models\RoomJudge;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionInvitation;
use App\Services\VersionRoleAssignmentService;
use App\Services\VersionRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

test('assignableRoleNames excludes Co-Registration Manager, which is assigned via its own dedicated screen', function () {
    $service = app(VersionRoleAssignmentService::class);

    expect($service->assignableRoleNames())->toBe([
        'Event Manager',
        'Registration Manager',
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

test('canManageAuditionEnvironment is true for Registration Manager held specifically on that Version', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $version = Version::factory()->create();

    grantVersionRole($user, $version, 'Registration Manager');

    expect($service->canManageAuditionEnvironment($user, $version))->toBeTrue();
});

test('canManageAuditionEnvironment is true for Co-Registration Manager held specifically on that Version', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $version = Version::factory()->create();

    grantVersionRole($user, $version, 'Co-Registration Manager');

    expect($service->canManageAuditionEnvironment($user, $version))->toBeTrue();
});

test('canManageAuditionEnvironment is false when Registration Manager is held on a sibling Version, not this one', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $versionA = Version::factory()->create(['event_id' => $event->id]);
    $versionB = Version::factory()->create(['event_id' => $event->id]);

    grantVersionRole($user, $versionA, 'Registration Manager');

    expect($service->canManageAuditionEnvironment($user, $versionB))->toBeFalse();
});

test('canManageAuditionEnvironment for a held Version is unaffected by an earlier check against a sibling Version without the role', function () {
    // Regression: Show::render() builds versionAuditionAccess by looping
    // every Version of the Event with the same $user instance. Spatie's
    // `roles` relation caches on that instance across the team-context
    // switch inside withVersion() — checking a Version without the role
    // first must not poison the result for a later Version where the role
    // genuinely is held.
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $versionWithoutRole = Version::factory()->create(['event_id' => $event->id]);
    $versionWithRole = Version::factory()->create(['event_id' => $event->id]);

    grantVersionRole($user, $versionWithRole, 'Registration Manager');

    expect($service->canManageAuditionEnvironment($user, $versionWithoutRole))->toBeFalse();
    expect($service->canManageAuditionEnvironment($user, $versionWithRole))->toBeTrue();
});

test('canManageAuditionEnvironment is true via the Event Manager sibling-version fallback', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $versionA = Version::factory()->create(['event_id' => $event->id]);
    $versionB = Version::factory()->create(['event_id' => $event->id]);

    grantVersionRole($user, $versionA, 'Event Manager');

    expect($service->canManageAuditionEnvironment($user, $versionB))->toBeTrue();
});

test('canManageAuditionEnvironment is true for Founder regardless of any role assignment', function () {
    $service = app(VersionRoleAssignmentService::class);
    $founder = makeFounder();
    $version = Version::factory()->create();

    expect($service->canManageAuditionEnvironment($founder, $version))->toBeTrue();
});

test('canManageAuditionEnvironment is false for an unrelated version-scoped role (e.g. Tab Room Manager)', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $version = Version::factory()->create();

    grantVersionRole($user, $version, 'Tab Room Manager');

    expect($service->canManageAuditionEnvironment($user, $version))->toBeFalse();
});

test('canManageReports is true for Registration Manager held specifically on that Version', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $version = Version::factory()->create();

    grantVersionRole($user, $version, 'Registration Manager');

    expect($service->canManageReports($user, $version))->toBeTrue();
});

test('canManageReports is true for Co-Registration Manager held specifically on that Version', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $version = Version::factory()->create();

    grantVersionRole($user, $version, 'Co-Registration Manager');

    expect($service->canManageReports($user, $version))->toBeTrue();
});

test('canManageReports is true for Founder and via the Event Manager sibling-version fallback', function () {
    $service = app(VersionRoleAssignmentService::class);
    $founder = makeFounder();
    $event = Event::factory()->create();
    $versionA = Version::factory()->create(['event_id' => $event->id]);
    $versionB = Version::factory()->create(['event_id' => $event->id]);

    expect($service->canManageReports($founder, $versionA))->toBeTrue();

    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $versionA, 'Event Manager');

    expect($service->canManageReports($eventManager, $versionB))->toBeTrue();
});

test('canManageReports is false for an unrelated version-scoped role (e.g. Tab Room Manager)', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $version = Version::factory()->create();

    grantVersionRole($user, $version, 'Tab Room Manager');

    expect($service->canManageReports($user, $version))->toBeFalse();
});

test('canManageTabRoom is true for Tab Room Manager held specifically on that Version', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $version = Version::factory()->create();

    grantVersionRole($user, $version, 'Tab Room Manager');

    expect($service->canManageTabRoom($user, $version))->toBeTrue();
});

test('canManageTabRoom is true for Founder and via the Event Manager sibling-version fallback', function () {
    $service = app(VersionRoleAssignmentService::class);
    $founder = makeFounder();
    $event = Event::factory()->create();
    $versionA = Version::factory()->create(['event_id' => $event->id]);
    $versionB = Version::factory()->create(['event_id' => $event->id]);

    expect($service->canManageTabRoom($founder, $versionA))->toBeTrue();

    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $versionA, 'Event Manager');

    expect($service->canManageTabRoom($eventManager, $versionB))->toBeTrue();
});

test('canManageTabRoom is false for an unrelated version-scoped role (e.g. Registration Manager)', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $version = Version::factory()->create();

    grantVersionRole($user, $version, 'Registration Manager');

    expect($service->canManageTabRoom($user, $version))->toBeFalse();
});

test('canManageTabRoomReports resolves identically to canManageTabRoom', function () {
    $service = app(VersionRoleAssignmentService::class);
    $tabRoomManager = User::factory()->create();
    $registrationManager = User::factory()->create();
    $version = Version::factory()->create();

    grantVersionRole($tabRoomManager, $version, 'Tab Room Manager');
    grantVersionRole($registrationManager, $version, 'Registration Manager');

    expect($service->canManageTabRoomReports($tabRoomManager, $version))->toBeTrue();
    expect($service->canManageTabRoomReports($registrationManager, $version))->toBeFalse();
});

test('reportCountyIds is null (unrestricted) for Founder and for an Event-wide Event Manager', function () {
    $service = app(VersionRoleAssignmentService::class);
    $founder = makeFounder();
    $event = Event::factory()->create();
    $versionA = Version::factory()->create(['event_id' => $event->id]);
    $versionB = Version::factory()->create(['event_id' => $event->id]);

    expect($service->reportCountyIds($founder, $versionA))->toBeNull();

    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $versionA, 'Event Manager');

    expect($service->reportCountyIds($eventManager, $versionB))->toBeNull();
});

test('reportCountyIds is null (unrestricted) for Registration Manager held specifically on that Version', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $version = Version::factory()->create();

    grantVersionRole($user, $version, 'Registration Manager');

    expect($service->reportCountyIds($user, $version))->toBeNull();
});

test('reportCountyIds returns exactly the Co-Registration Manager\'s assigned counties on that Version', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $version = Version::factory()->create();
    $countyA = County::factory()->create();
    $countyB = County::factory()->create();
    $unassignedCounty = County::factory()->create();

    grantVersionRole($user, $version, 'Co-Registration Manager');
    CoRegistrationManagerCounty::create(['version_id' => $version->id, 'user_id' => $user->id, 'county_id' => $countyA->id]);
    CoRegistrationManagerCounty::create(['version_id' => $version->id, 'user_id' => $user->id, 'county_id' => $countyB->id]);

    $result = $service->reportCountyIds($user, $version);

    expect($result)->toEqualCanonicalizing([$countyA->id, $countyB->id]);
    expect($result)->not->toContain($unassignedCounty->id);
});

test('reportCountyIds fails closed to an empty array for a user holding neither Registration nor Co-Registration Manager', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $version = Version::factory()->create();

    grantVersionRole($user, $version, 'Tab Room Manager');

    expect($service->reportCountyIds($user, $version))->toBe([]);
});

test('reportCountyIds for a held Version is unaffected by an earlier check against a sibling Version without the role', function () {
    // Same stale-relation-cache hazard as canManageAuditionEnvironment (see the
    // regression test above) — reportCountyIds shares its withVersion()/
    // unsetRelation('roles') pattern, so it needs the same coverage.
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $versionWithoutRole = Version::factory()->create(['event_id' => $event->id]);
    $versionWithRole = Version::factory()->create(['event_id' => $event->id]);

    grantVersionRole($user, $versionWithRole, 'Registration Manager');

    expect($service->reportCountyIds($user, $versionWithoutRole))->toBe([]);
    expect($service->reportCountyIds($user, $versionWithRole))->toBeNull();
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

test('assignRole auto-invites the target Teacher to the Version', function () {
    $service = app(VersionRoleAssignmentService::class);
    $founder = makeFounder();
    $version = Version::factory()->create();
    $teacher = Teacher::factory()->create();

    $service->assignRole($founder, $version, $teacher->user, 'Tab Room Manager');

    $invitation = VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->first();
    expect($invitation)->not->toBeNull();
    expect($invitation->invited_by_user_id)->toBe($founder->id);
});

test('assignRole does not throw when the target user has no Teacher record', function () {
    $service = app(VersionRoleAssignmentService::class);
    $founder = makeFounder();
    $version = Version::factory()->create();
    $targetUser = User::factory()->create();

    $service->assignRole($founder, $version, $targetUser, 'Tab Room Manager');

    expect(VersionInvitation::where('version_id', $version->id)->exists())->toBeFalse();
});

test('assignRole does not duplicate an invitation when a second role is granted to an already-invited Teacher', function () {
    $service = app(VersionRoleAssignmentService::class);
    $founder = makeFounder();
    $version = Version::factory()->create();
    $teacher = Teacher::factory()->create();

    $service->assignRole($founder, $version, $teacher->user, 'Tab Room Manager');
    $service->assignRole($founder, $version, $teacher->user, 'Rehearsal Manager');

    expect(VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->count())->toBe(1);
});

test('bootstrapEventManager auto-invites the creator\'s Teacher to the Version', function () {
    $service = app(VersionRoleAssignmentService::class);
    $version = Version::factory()->create();
    $teacher = Teacher::factory()->create();

    $service->bootstrapEventManager($teacher->user, $version);

    expect(VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->exists())->toBeTrue();
});

test('assignCoRegistrationManager auto-invites the target Teacher to the Version', function () {
    $service = app(VersionRoleAssignmentService::class);
    $founder = makeFounder();
    $version = Version::factory()->create();
    $teacher = Teacher::factory()->create();

    $service->assignCoRegistrationManager($founder, $version, $teacher->user, []);

    expect(VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->exists())->toBeTrue();
});

test('revokeRole leaves the auto-created invitation in place', function () {
    $service = app(VersionRoleAssignmentService::class);
    $founder = makeFounder();
    $version = Version::factory()->create();
    $teacher = Teacher::factory()->create();

    $service->assignRole($founder, $version, $teacher->user, 'Tab Room Manager');
    $service->revokeRole($founder, $version, $teacher->user, 'Tab Room Manager');

    expect(VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->exists())->toBeTrue();
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

test('assignRole aborts with 400 when the role is Co-Registration Manager, since it is not on the general Roles-tab list', function () {
    $service = app(VersionRoleAssignmentService::class);
    $founder = makeFounder();
    $version = Version::factory()->create();
    $targetUser = User::factory()->create();

    expect(fn () => $service->assignRole($founder, $version, $targetUser, 'Co-Registration Manager'))
        ->toThrow(HttpException::class);
});

test('canManageCoRegistrationManagers is true for Registration Manager held specifically on that Version', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $version = Version::factory()->create();

    grantVersionRole($user, $version, 'Registration Manager');

    expect($service->canManageCoRegistrationManagers($user, $version))->toBeTrue();
});

test('canManageCoRegistrationManagers is false for a Co-Registration Manager (no further delegation)', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $version = Version::factory()->create();

    grantVersionRole($user, $version, 'Co-Registration Manager');

    expect($service->canManageCoRegistrationManagers($user, $version))->toBeFalse();
});

test('canManageCoRegistrationManagers is true for Founder and for an Event Manager, false for an unrelated role', function () {
    $service = app(VersionRoleAssignmentService::class);
    $founder = makeFounder();
    $version = Version::factory()->create();
    expect($service->canManageCoRegistrationManagers($founder, $version))->toBeTrue();

    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $version, 'Event Manager');
    expect($service->canManageCoRegistrationManagers($eventManager, $version))->toBeTrue();

    $unrelated = User::factory()->create();
    grantVersionRole($unrelated, $version, 'Tab Room Manager');
    expect($service->canManageCoRegistrationManagers($unrelated, $version))->toBeFalse();
});

test('canManageWebRegistration is true for Web Registration Manager held specifically on that Version', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $version = Version::factory()->create();

    grantVersionRole($user, $version, 'Web Registration Manager');

    expect($service->canManageWebRegistration($user, $version))->toBeTrue();
});

test('canManageWebRegistration is false for Registration Manager or Co-Registration Manager (source doc names only Web Registration Manager)', function () {
    $service = app(VersionRoleAssignmentService::class);
    $version = Version::factory()->create();

    $registrationManager = User::factory()->create();
    grantVersionRole($registrationManager, $version, 'Registration Manager');
    expect($service->canManageWebRegistration($registrationManager, $version))->toBeFalse();

    $coRegistrationManager = User::factory()->create();
    grantVersionRole($coRegistrationManager, $version, 'Co-Registration Manager');
    expect($service->canManageWebRegistration($coRegistrationManager, $version))->toBeFalse();
});

test('canManageWebRegistration is true for Founder and for an Event Manager, false for an unrelated role', function () {
    $service = app(VersionRoleAssignmentService::class);
    $founder = makeFounder();
    $version = Version::factory()->create();
    expect($service->canManageWebRegistration($founder, $version))->toBeTrue();

    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $version, 'Event Manager');
    expect($service->canManageWebRegistration($eventManager, $version))->toBeTrue();

    $unrelated = User::factory()->create();
    grantVersionRole($unrelated, $version, 'Tab Room Manager');
    expect($service->canManageWebRegistration($unrelated, $version))->toBeFalse();
});

test('assignCoRegistrationManager grants the role and writes the county rows, replacing any prior set', function () {
    $service = app(VersionRoleAssignmentService::class);
    $version = Version::factory()->create();
    $registrationManager = User::factory()->create();
    grantVersionRole($registrationManager, $version, 'Registration Manager');

    $countyA = County::factory()->create();
    $countyB = County::factory()->create();
    $version->counties()->create(['county_id' => $countyA->id]);
    $version->counties()->create(['county_id' => $countyB->id]);

    $targetUser = User::factory()->create();

    $service->assignCoRegistrationManager($registrationManager, $version, $targetUser, [$countyA->id]);

    expect($service->assignmentsForVersion($version)->get('Co-Registration Manager')->pluck('id'))->toContain($targetUser->id);
    expect(CoRegistrationManagerCounty::where('version_id', $version->id)->where('user_id', $targetUser->id)->pluck('county_id')->all())
        ->toBe([$countyA->id]);

    // Reassigning replaces the prior county set rather than appending.
    $service->assignCoRegistrationManager($registrationManager, $version, $targetUser, [$countyB->id]);

    expect(CoRegistrationManagerCounty::where('version_id', $version->id)->where('user_id', $targetUser->id)->pluck('county_id')->all())
        ->toBe([$countyB->id]);
});

test('assignCoRegistrationManager aborts with 403 when the acting user cannot manage Co-Registration Managers', function () {
    $service = app(VersionRoleAssignmentService::class);
    $version = Version::factory()->create();
    $unauthorizedActor = User::factory()->create();
    $targetUser = User::factory()->create();

    expect(fn () => $service->assignCoRegistrationManager($unauthorizedActor, $version, $targetUser, []))
        ->toThrow(HttpException::class);
});

test('revokeCoRegistrationManager removes the role and deletes the county rows', function () {
    $service = app(VersionRoleAssignmentService::class);
    $version = Version::factory()->create();
    $founder = makeFounder();
    $county = County::factory()->create();
    $version->counties()->create(['county_id' => $county->id]);
    $targetUser = User::factory()->create();

    $service->assignCoRegistrationManager($founder, $version, $targetUser, [$county->id]);
    expect($service->assignmentsForVersion($version)->get('Co-Registration Manager')->pluck('id'))->toContain($targetUser->id);

    $service->revokeCoRegistrationManager($founder, $version, $targetUser);

    expect($service->assignmentsForVersion($version)->get('Co-Registration Manager')->pluck('id'))->not->toContain($targetUser->id);
    expect(CoRegistrationManagerCounty::where('version_id', $version->id)->where('user_id', $targetUser->id)->exists())->toBeFalse();
});

test('revokeCoRegistrationManager aborts with 403 when the acting user cannot manage Co-Registration Managers', function () {
    $service = app(VersionRoleAssignmentService::class);
    $version = Version::factory()->create();
    $founder = makeFounder();
    $targetUser = User::factory()->create();
    $service->assignCoRegistrationManager($founder, $version, $targetUser, []);

    $unauthorizedActor = User::factory()->create();

    expect(fn () => $service->revokeCoRegistrationManager($unauthorizedActor, $version, $targetUser))
        ->toThrow(HttpException::class);
});

test('canViewEvent is true for a RoomJudge assignment on any Version of the Event, though it holds no Spatie role at all', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);

    RoomJudge::factory()->create(['version_id' => $version->id, 'user_id' => $user->id]);

    expect($service->canViewEvent($user, $event))->toBeTrue();
    expect($service->canManageEvent($user, $event))->toBeFalse();
});

test('isJudgeForEvent is false for a user with no RoomJudge assignment on any Version of the Event', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    Version::factory()->create(['event_id' => $event->id]);

    expect($service->isJudgeForEvent($user, $event))->toBeFalse();
});

test('eventIdsVisibleTo and canAccessEventsSection include Events reached only via a RoomJudge assignment', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);

    RoomJudge::factory()->create(['version_id' => $version->id, 'user_id' => $user->id]);

    expect($service->eventIdsVisibleTo($user))->toBe([$event->id]);
    expect($service->canAccessEventsSection($user))->toBeTrue();
});

test('judgeEventIds returns only Events reached via a RoomJudge assignment for that user', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $judgedEvent = Event::factory()->create();
    $unrelatedEvent = Event::factory()->create();
    $judgedVersion = Version::factory()->create(['event_id' => $judgedEvent->id]);
    Version::factory()->create(['event_id' => $unrelatedEvent->id]);

    RoomJudge::factory()->create(['version_id' => $judgedVersion->id, 'user_id' => $user->id]);

    expect($service->judgeEventIds($user))->toBe([$judgedEvent->id]);
});

test('judgeEventIds returns an empty array for a user with no RoomJudge assignments', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();

    expect($service->judgeEventIds($user))->toBe([]);
});

function makeAdjudicatableVersion(User $user, Event $event): Version
{
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    RoomJudge::factory()->create(['version_id' => $version->id, 'user_id' => $user->id]);
    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => 'adjudication',
        'start_at' => now()->subDay(),
        'end_at' => now()->addDay(),
    ]);

    return $version;
}

test('canAdjudicate is true when active, judged on this Version, and within the adjudication window', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $version = makeAdjudicatableVersion($user, $event);

    expect($service->canAdjudicate($user, $version))->toBeTrue();
});

test('canAdjudicate is false when the Version is not active', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $version = makeAdjudicatableVersion($user, $event);
    $version->update(['status' => 'sandbox']);

    expect($service->canAdjudicate($user, $version))->toBeFalse();
});

test('canAdjudicate is false when the RoomJudge assignment is on a sibling Version, not this one', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    makeAdjudicatableVersion($user, $event);

    $otherVersion = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    VersionDate::create([
        'version_id' => $otherVersion->id,
        'date_type' => 'adjudication',
        'start_at' => now()->subDay(),
        'end_at' => now()->addDay(),
    ]);

    expect($service->canAdjudicate($user, $otherVersion))->toBeFalse();
});

test('canAdjudicate is false with no adjudication VersionDate row at all', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    RoomJudge::factory()->create(['version_id' => $version->id, 'user_id' => $user->id]);

    expect($service->canAdjudicate($user, $version))->toBeFalse();
});

test('canAdjudicate is false before the adjudication window starts', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    RoomJudge::factory()->create(['version_id' => $version->id, 'user_id' => $user->id]);
    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => 'adjudication',
        'start_at' => now()->addDay(),
        'end_at' => now()->addDays(2),
    ]);

    expect($service->canAdjudicate($user, $version))->toBeFalse();
});

test('canAdjudicate is false after the adjudication window ends', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    RoomJudge::factory()->create(['version_id' => $version->id, 'user_id' => $user->id]);
    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => 'adjudication',
        'start_at' => now()->subDays(2),
        'end_at' => now()->subDay(),
    ]);

    expect($service->canAdjudicate($user, $version))->toBeFalse();
});

test('canAdjudicate is false for an otherwise-qualifying judge who is a currently-enrolled student', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $school = School::factory()->create();
    $student->schools()->attach($school->id, ['is_active' => true, 'class_of' => $school->senior_year]);

    $event = Event::factory()->create();
    $version = makeAdjudicatableVersion($user, $event);

    expect($service->canAdjudicate($user, $version))->toBeFalse();
});

test('canAdjudicate is true for a judge who has a Student record but has already graduated everywhere', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $school = School::factory()->create();
    $student->schools()->attach($school->id, ['is_active' => true, 'class_of' => $school->senior_year - 1]);

    $event = Event::factory()->create();
    $version = makeAdjudicatableVersion($user, $event);

    expect($service->canAdjudicate($user, $version))->toBeTrue();
});

test('adjudicatableVersionFor returns the qualifying Version, ignoring non-qualifying siblings', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    Version::factory()->create(['event_id' => $event->id, 'status' => 'sandbox']);
    $qualifyingVersion = makeAdjudicatableVersion($user, $event);

    expect($service->adjudicatableVersionFor($user, $event)->id)->toBe($qualifyingVersion->id);
});

test('adjudicatableVersionFor returns null when no Version of the Event qualifies', function () {
    $service = app(VersionRoleAssignmentService::class);
    $user = User::factory()->create();
    $event = Event::factory()->create();
    Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);

    expect($service->adjudicatableVersionFor($user, $event))->toBeNull();
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
