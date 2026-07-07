<?php

declare(strict_types=1);

use App\Enums\VersionInvitationStatus;
use App\Livewire\Events\VersionInvitations;
use App\Models\County;
use App\Models\Event;
use App\Models\Organization;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionCounty;
use App\Models\VersionInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function attachEligibleTeacherSchool(Teacher $teacher, ?County $county = null, ?School $school = null): School
{
    $school ??= School::factory()->create($county !== null ? ['county_id' => $county->id] : []);
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);

    return $school;
}

function makeNamedTeacher(string $firstName, string $lastName, string $email): Teacher
{
    $user = User::factory()->create(['first_name' => $firstName, 'last_name' => $lastName, 'email' => $email]);

    return Teacher::factory()->create(['user_id' => $user->id]);
}

function makeInvitationsVersion(): Version
{
    $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    return Version::factory()->create(['event_id' => $event->id]);
}

/**
 * A county-restricted Version, isolated from the seeded teacher/school
 * fixtures (which run before every test via TestCase::$seed) that would
 * otherwise also qualify under an unrestricted county leg and pollute
 * bulk-action counts.
 */
function makeRestrictedInvitationsVersion(): array
{
    $county = County::factory()->create();
    $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $version = Version::factory()->create(['event_id' => $event->id]);
    VersionCounty::create(['version_id' => $version->id, 'county_id' => $county->id]);

    return [$version, $county];
}

test('mount aborts with 403 for a user with no version-scoped role on the Version', function () {
    $user = User::factory()->create();
    $version = makeInvitationsVersion();

    Livewire::actingAs($user)
        ->test(VersionInvitations::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount allows a user holding Event Manager on the Version', function () {
    $user = User::factory()->create();
    $version = makeInvitationsVersion();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionInvitations::class, ['version' => $version])
        ->assertOk();
});

test('mount allows the Founder regardless of any role assignment', function () {
    $founder = makeFounder();
    $version = makeInvitationsVersion();

    Livewire::actingAs($founder)
        ->test(VersionInvitations::class, ['version' => $version])
        ->assertOk();
});

test('toggle invites an eligible teacher who has no existing invitation', function () {
    $eventManager = User::factory()->create();
    $version = makeInvitationsVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $teacher = Teacher::factory()->create();
    attachEligibleTeacherSchool($teacher);

    Livewire::actingAs($eventManager)
        ->test(VersionInvitations::class, ['version' => $version])
        ->call('toggle', $teacher->id)
        ->assertDispatched('toast-show', slots: ['text' => 'Teacher invited.']);

    $invitation = VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->first();

    expect($invitation)->not->toBeNull();
    expect($invitation->getRawOriginal('status'))->toBe('invited');
    expect($invitation->invited_by_user_id)->toBe($eventManager->id);
});

test('toggle removes an existing invited invitation (uninvite)', function () {
    $eventManager = User::factory()->create();
    $version = makeInvitationsVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $teacher = Teacher::factory()->create();
    attachEligibleTeacherSchool($teacher);

    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => $eventManager->id,
    ]);

    Livewire::actingAs($eventManager)
        ->test(VersionInvitations::class, ['version' => $version])
        ->call('toggle', $teacher->id)
        ->assertDispatched('toast-show', slots: ['text' => 'Invitation removed.']);

    expect(VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->exists())->toBeFalse();
});

test('toggle is blocked and leaves the row intact when the invitation is already obligated', function () {
    $eventManager = User::factory()->create();
    $version = makeInvitationsVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $teacher = Teacher::factory()->create();
    attachEligibleTeacherSchool($teacher);

    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationStatus::Obligated->value,
        'invited_at' => now(),
        'invited_by_user_id' => $eventManager->id,
    ]);

    Livewire::actingAs($eventManager)
        ->test(VersionInvitations::class, ['version' => $version])
        ->call('toggle', $teacher->id)
        ->assertDispatched('toast-show', slots: [
            'text' => 'Cannot remove this invitation — the teacher has already agreed to Version obligations.',
        ]);

    $invitation = VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->first();

    expect($invitation)->not->toBeNull();
    expect($invitation->getRawOriginal('status'))->toBe('obligated');
});

test('toggle is blocked and leaves the row intact when the invitation is already participating', function () {
    $eventManager = User::factory()->create();
    $version = makeInvitationsVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $teacher = Teacher::factory()->create();
    attachEligibleTeacherSchool($teacher);

    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationStatus::Participating->value,
        'invited_at' => now(),
        'invited_by_user_id' => $eventManager->id,
    ]);

    Livewire::actingAs($eventManager)
        ->test(VersionInvitations::class, ['version' => $version])
        ->call('toggle', $teacher->id);

    $invitation = VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->first();

    expect($invitation)->not->toBeNull();
    expect($invitation->getRawOriginal('status'))->toBe('participating');
});

test('inviteAll invites every eligible teacher with no existing invitation and leaves already-invited teachers alone', function () {
    [$version, $county] = makeRestrictedInvitationsVersion();
    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $newTeacher = Teacher::factory()->create();
    attachEligibleTeacherSchool($newTeacher, $county);

    $alreadyInvitedTeacher = Teacher::factory()->create();
    attachEligibleTeacherSchool($alreadyInvitedTeacher, $county);
    $existing = VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $alreadyInvitedTeacher->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now()->subDay(),
        'invited_by_user_id' => $eventManager->id,
    ]);

    Livewire::actingAs($eventManager)
        ->test(VersionInvitations::class, ['version' => $version])
        ->call('inviteAll')
        ->assertDispatched('toast-show', slots: ['text' => '1 teacher(s) invited.']);

    expect(VersionInvitation::where('version_id', $version->id)->where('teacher_id', $newTeacher->id)->exists())->toBeTrue();
    expect(VersionInvitation::find($existing->id)->getRawOriginal('invited_at'))->toBe($existing->getRawOriginal('invited_at'));
});

test('removeAll deletes invited invitations but skips obligated/participating ones', function () {
    [$version, $county] = makeRestrictedInvitationsVersion();
    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $invitedTeacher = Teacher::factory()->create();
    attachEligibleTeacherSchool($invitedTeacher, $county);
    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $invitedTeacher->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => $eventManager->id,
    ]);

    $obligatedTeacher = Teacher::factory()->create();
    attachEligibleTeacherSchool($obligatedTeacher, $county);
    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $obligatedTeacher->id,
        'status' => VersionInvitationStatus::Obligated->value,
        'invited_at' => now(),
        'invited_by_user_id' => $eventManager->id,
    ]);

    $eligibleOnlyTeacher = Teacher::factory()->create();
    attachEligibleTeacherSchool($eligibleOnlyTeacher, $county);

    Livewire::actingAs($eventManager)
        ->test(VersionInvitations::class, ['version' => $version])
        ->call('removeAll')
        ->assertDispatched('toast-show', slots: [
            'text' => '1 invitation(s) removed. 1 teacher(s) skipped — already agreed to Version obligations.',
        ]);

    expect(VersionInvitation::where('version_id', $version->id)->where('teacher_id', $invitedTeacher->id)->exists())->toBeFalse();
    expect(VersionInvitation::where('version_id', $version->id)->where('teacher_id', $obligatedTeacher->id)->exists())->toBeTrue();
    expect(VersionInvitation::where('version_id', $version->id)->where('teacher_id', $eligibleOnlyTeacher->id)->exists())->toBeFalse();
});

test('search matches by teacher name and hides non-matching teachers', function () {
    [$version, $county] = makeRestrictedInvitationsVersion();
    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $match = makeNamedTeacher('Zelda', 'Zephyr', 'zelda@example.com');
    attachEligibleTeacherSchool($match, $county);

    $nonMatch = makeNamedTeacher('Amy', 'Adams', 'amy@example.com');
    attachEligibleTeacherSchool($nonMatch, $county);

    Livewire::actingAs($eventManager)
        ->test(VersionInvitations::class, ['version' => $version])
        ->set('search', 'zephyr')
        ->assertSee('Zelda Zephyr')
        ->assertDontSee('Amy Adams');
});

test('search matches by teacher email', function () {
    [$version, $county] = makeRestrictedInvitationsVersion();
    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $match = makeNamedTeacher('Zelda', 'Zephyr', 'findme@example.com');
    attachEligibleTeacherSchool($match, $county);

    $nonMatch = makeNamedTeacher('Amy', 'Adams', 'someoneelse@example.com');
    attachEligibleTeacherSchool($nonMatch, $county);

    Livewire::actingAs($eventManager)
        ->test(VersionInvitations::class, ['version' => $version])
        ->set('search', 'findme@')
        ->assertSee('Zelda Zephyr')
        ->assertDontSee('Amy Adams');
});

test('search matches by school name', function () {
    [$version, $county] = makeRestrictedInvitationsVersion();
    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $match = makeNamedTeacher('Zelda', 'Zephyr', 'zelda@example.com');
    attachEligibleTeacherSchool($match, $county, School::factory()->create(['name' => 'Unique Findable Academy', 'county_id' => $county->id]));

    $nonMatch = makeNamedTeacher('Amy', 'Adams', 'amy@example.com');
    attachEligibleTeacherSchool($nonMatch, $county, School::factory()->create(['name' => 'Some Other School', 'county_id' => $county->id]));

    Livewire::actingAs($eventManager)
        ->test(VersionInvitations::class, ['version' => $version])
        ->set('search', 'findable academy')
        ->assertSee('Zelda Zephyr')
        ->assertDontSee('Amy Adams');
});

test('search shows the "no matches" callout without claiming no one is eligible', function () {
    [$version, $county] = makeRestrictedInvitationsVersion();
    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $teacher = makeNamedTeacher('Zelda', 'Zephyr', 'zelda@example.com');
    attachEligibleTeacherSchool($teacher, $county);

    Livewire::actingAs($eventManager)
        ->test(VersionInvitations::class, ['version' => $version])
        ->set('search', 'nobody-matches-this')
        ->assertSee('No teachers match your search or filter.')
        ->assertDontSee('No teachers are currently eligible for this Version.');
});

test('the roster defaults to ascending order by teacher name', function () {
    [$version, $county] = makeRestrictedInvitationsVersion();
    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $first = makeNamedTeacher('Amy', 'Adams', 'amy@example.com');
    attachEligibleTeacherSchool($first, $county);

    $second = makeNamedTeacher('Zelda', 'Zephyr', 'zelda@example.com');
    attachEligibleTeacherSchool($second, $county);

    Livewire::actingAs($eventManager)
        ->test(VersionInvitations::class, ['version' => $version])
        ->assertSet('sortColumn', 'teacher')
        ->assertSet('sortDirection', 'asc')
        ->assertSeeInOrder(['Amy Adams', 'Zelda Zephyr']);
});

test('sortBy on the same column already sorted toggles direction and reverses the roster', function () {
    [$version, $county] = makeRestrictedInvitationsVersion();
    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $first = makeNamedTeacher('Amy', 'Adams', 'amy@example.com');
    attachEligibleTeacherSchool($first, $county);

    $second = makeNamedTeacher('Zelda', 'Zephyr', 'zelda@example.com');
    attachEligibleTeacherSchool($second, $county);

    Livewire::actingAs($eventManager)
        ->test(VersionInvitations::class, ['version' => $version])
        ->call('sortBy', 'teacher')
        ->assertSet('sortDirection', 'desc')
        ->assertSeeInOrder(['Zelda Zephyr', 'Amy Adams']);
});

test('sortBy switching to a new column resets direction to asc', function () {
    [$version, $county] = makeRestrictedInvitationsVersion();
    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $version, 'Event Manager');

    Livewire::actingAs($eventManager)
        ->test(VersionInvitations::class, ['version' => $version])
        ->call('sortBy', 'teacher')
        ->assertSet('sortDirection', 'desc')
        ->call('sortBy', 'email')
        ->assertSet('sortColumn', 'email')
        ->assertSet('sortDirection', 'asc');
});

test('statusFilter narrows the roster to only the selected status', function () {
    [$version, $county] = makeRestrictedInvitationsVersion();
    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $eligibleTeacher = makeNamedTeacher('Amy', 'Adams', 'amy@example.com');
    attachEligibleTeacherSchool($eligibleTeacher, $county);

    $invitedTeacher = makeNamedTeacher('Zelda', 'Zephyr', 'zelda@example.com');
    attachEligibleTeacherSchool($invitedTeacher, $county);
    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $invitedTeacher->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => $eventManager->id,
    ]);

    Livewire::actingAs($eventManager)
        ->test(VersionInvitations::class, ['version' => $version])
        ->set('statusFilter', 'invited')
        ->assertSee('Zelda Zephyr')
        ->assertDontSee('Amy Adams');
});

test('the status summary counts reflect the full roster regardless of the active search/filter', function () {
    [$version, $county] = makeRestrictedInvitationsVersion();
    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $eligibleTeacher = makeNamedTeacher('Amy', 'Adams', 'amy@example.com');
    attachEligibleTeacherSchool($eligibleTeacher, $county);

    $invitedTeacher = makeNamedTeacher('Zelda', 'Zephyr', 'zelda@example.com');
    attachEligibleTeacherSchool($invitedTeacher, $county);
    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $invitedTeacher->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => $eventManager->id,
    ]);

    Livewire::actingAs($eventManager)
        ->test(VersionInvitations::class, ['version' => $version])
        ->set('search', 'zelda')
        ->assertViewHas('statusCounts', [
            'eligible' => 1,
            'invited' => 1,
            'obligated' => 0,
            'participating' => 0,
        ]);
});

test('filterByStatus sets the status filter and clicking the same status again clears it', function () {
    [$version, $county] = makeRestrictedInvitationsVersion();
    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $eligibleTeacher = makeNamedTeacher('Amy', 'Adams', 'amy@example.com');
    attachEligibleTeacherSchool($eligibleTeacher, $county);

    $invitedTeacher = makeNamedTeacher('Zelda', 'Zephyr', 'zelda@example.com');
    attachEligibleTeacherSchool($invitedTeacher, $county);
    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $invitedTeacher->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => $eventManager->id,
    ]);

    Livewire::actingAs($eventManager)
        ->test(VersionInvitations::class, ['version' => $version])
        ->call('filterByStatus', 'invited')
        ->assertSet('statusFilter', 'invited')
        ->assertSee('Zelda Zephyr')
        ->assertDontSee('Amy Adams')
        ->call('filterByStatus', 'invited')
        ->assertSet('statusFilter', '')
        ->assertSee('Zelda Zephyr')
        ->assertSee('Amy Adams');
});
