<?php

declare(strict_types=1);

use App\Enums\VersionInvitationStatus;
use App\Models\County;
use App\Models\Event;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionCounty;
use App\Models\VersionInvitation;
use App\Services\VersionInvitationEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function attachTeacherToVersionSchool(Teacher $teacher, School $school, bool $isActive = true, bool $verified = true): void
{
    $teacher->schools()->attach($school->id, [
        'is_active' => $isActive,
        'verified_at' => $verified ? now() : null,
    ]);
}

/**
 * Builds an Event (optionally under a parent org) and a Version restricted
 * to the given counties (none = unrestricted).
 *
 * @param  list<County>  $restrictedCounties
 */
function makeInvitationVersion(Organization $organization, array $restrictedCounties = []): Version
{
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $version = Version::factory()->create(['event_id' => $event->id]);

    foreach ($restrictedCounties as $county) {
        VersionCounty::create(['version_id' => $version->id, 'county_id' => $county->id]);
    }

    return $version;
}

test('eligibleTeachers includes a teacher whose school county is in the Version\'s configured counties', function () {
    $organization = Organization::factory()->create();
    $county = County::factory()->create();
    $version = makeInvitationVersion($organization, [$county]);

    $teacher = Teacher::factory()->create();
    $school = School::factory()->create(['county_id' => $county->id]);
    attachTeacherToVersionSchool($teacher, $school);

    $result = app(VersionInvitationEligibilityService::class)->eligibleTeachers($version);

    expect($result->pluck('id'))->toContain($teacher->id);
});

test('eligibleTeachers excludes a teacher whose county does not match and who has no membership, when county-restricted', function () {
    $organization = Organization::factory()->create();
    $configuredCounty = County::factory()->create();
    $version = makeInvitationVersion($organization, [$configuredCounty]);

    $teacher = Teacher::factory()->create();
    $otherCounty = County::factory()->create();
    $school = School::factory()->create(['county_id' => $otherCounty->id]);
    attachTeacherToVersionSchool($teacher, $school);

    $result = app(VersionInvitationEligibilityService::class)->eligibleTeachers($version);

    expect($result->pluck('id'))->not->toContain($teacher->id);
});

test('eligibleTeachers includes a teacher via organization membership even when the county does not match', function () {
    $organization = Organization::factory()->create();
    $configuredCounty = County::factory()->create();
    $version = makeInvitationVersion($organization, [$configuredCounty]);

    $teacher = Teacher::factory()->create();
    $otherCounty = County::factory()->create();
    $school = School::factory()->create(['county_id' => $otherCounty->id]);
    attachTeacherToVersionSchool($teacher, $school);

    Membership::factory()->create(['teacher_id' => $teacher->id, 'organization_id' => $organization->id]);

    $result = app(VersionInvitationEligibilityService::class)->eligibleTeachers($version);

    expect($result->pluck('id'))->toContain($teacher->id);
});

test('eligibleTeachers treats an expired membership as still qualifying', function () {
    $organization = Organization::factory()->create();
    $configuredCounty = County::factory()->create();
    $version = makeInvitationVersion($organization, [$configuredCounty]);

    $teacher = Teacher::factory()->create();
    $otherCounty = County::factory()->create();
    $school = School::factory()->create(['county_id' => $otherCounty->id]);
    attachTeacherToVersionSchool($teacher, $school);

    Membership::factory()->create([
        'teacher_id' => $teacher->id,
        'organization_id' => $organization->id,
        'membership_expires_at' => now()->subYear()->format('Y-m-d'),
    ]);

    $result = app(VersionInvitationEligibilityService::class)->eligibleTeachers($version);

    expect($result->pluck('id'))->toContain($teacher->id);
});

test('eligibleTeachers is unrestricted by county when the Version has no configured counties', function () {
    $organization = Organization::factory()->create();
    $version = makeInvitationVersion($organization);

    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();
    attachTeacherToVersionSchool($teacher, $school);
    // No membership at all — must pass on the unrestricted county leg alone.

    $result = app(VersionInvitationEligibilityService::class)->eligibleTeachers($version);

    expect($result->pluck('id'))->toContain($teacher->id);
});

test('eligibleTeachers excludes a teacher with no active, verified school even if they hold a membership', function () {
    $organization = Organization::factory()->create();
    $version = makeInvitationVersion($organization);

    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();
    attachTeacherToVersionSchool($teacher, $school, isActive: true, verified: false);

    Membership::factory()->create(['teacher_id' => $teacher->id, 'organization_id' => $organization->id]);

    $result = app(VersionInvitationEligibilityService::class)->eligibleTeachers($version);

    expect($result->pluck('id'))->not->toContain($teacher->id);
});

test('eligibleTeachers resolves membership against the root organization for a child org\'s Event', function () {
    $rootOrganization = Organization::factory()->create();
    $childOrganization = Organization::factory()->create(['parent_id' => $rootOrganization->id]);
    $configuredCounty = County::factory()->create();
    $version = makeInvitationVersion($childOrganization, [$configuredCounty]);

    $teacher = Teacher::factory()->create();
    $otherCounty = County::factory()->create();
    $school = School::factory()->create(['county_id' => $otherCounty->id]);
    attachTeacherToVersionSchool($teacher, $school);

    Membership::factory()->create(['teacher_id' => $teacher->id, 'organization_id' => $rootOrganization->id]);

    $result = app(VersionInvitationEligibilityService::class)->eligibleTeachers($version);

    expect($result->pluck('id'))->toContain($teacher->id);
});

test('roster shows eligible status and a null invitation for a teacher with no version_invitations row', function () {
    $organization = Organization::factory()->create();
    $version = makeInvitationVersion($organization);

    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();
    attachTeacherToVersionSchool($teacher, $school);

    $row = app(VersionInvitationEligibilityService::class)->roster($version)
        ->first(fn ($r) => $r->teacher->id === $teacher->id);

    expect($row->status)->toBe('eligible');
    expect($row->invitation)->toBeNull();
});

test('roster reflects the invited status once a version_invitations row exists', function () {
    $organization = Organization::factory()->create();
    $version = makeInvitationVersion($organization);

    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();
    attachTeacherToVersionSchool($teacher, $school);

    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    $row = app(VersionInvitationEligibilityService::class)->roster($version)
        ->first(fn ($r) => $r->teacher->id === $teacher->id);

    expect($row->status)->toBe('invited');
    expect($row->invitation)->not->toBeNull();
});

test('roster reflects obligated/participating statuses on an existing row, even though this phase never sets them', function () {
    $organization = Organization::factory()->create();
    $version = makeInvitationVersion($organization);

    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();
    attachTeacherToVersionSchool($teacher, $school);

    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationStatus::Participating->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    $row = app(VersionInvitationEligibilityService::class)->roster($version)
        ->first(fn ($r) => $r->teacher->id === $teacher->id);

    expect($row->status)->toBe('participating');
});

test('roster prefers a county-matching school over a non-matching one, regardless of alphabetical order', function () {
    $organization = Organization::factory()->create();
    $matchingCounty = County::factory()->create();
    $version = makeInvitationVersion($organization, [$matchingCounty]);

    $teacher = Teacher::factory()->create();
    $otherCounty = County::factory()->create();
    $nonMatchingSchool = School::factory()->create(['name' => 'Alpha School', 'county_id' => $otherCounty->id]);
    $matchingSchool = School::factory()->create(['name' => 'Zeta School', 'county_id' => $matchingCounty->id]);
    attachTeacherToVersionSchool($teacher, $nonMatchingSchool);
    attachTeacherToVersionSchool($teacher, $matchingSchool);

    $row = app(VersionInvitationEligibilityService::class)->roster($version)
        ->first(fn ($r) => $r->teacher->id === $teacher->id);

    expect($row->school->id)->toBe($matchingSchool->id);
});

test('roster falls back to the first school alphabetically when the teacher qualifies only via membership', function () {
    $organization = Organization::factory()->create();
    $configuredCounty = County::factory()->create();
    $version = makeInvitationVersion($organization, [$configuredCounty]);

    $teacher = Teacher::factory()->create();
    $otherCounty = County::factory()->create();
    $schoolA = School::factory()->create(['name' => 'Alpha School', 'county_id' => $otherCounty->id]);
    $schoolB = School::factory()->create(['name' => 'Beta School', 'county_id' => $otherCounty->id]);
    attachTeacherToVersionSchool($teacher, $schoolB);
    attachTeacherToVersionSchool($teacher, $schoolA);

    Membership::factory()->create(['teacher_id' => $teacher->id, 'organization_id' => $organization->id]);

    $row = app(VersionInvitationEligibilityService::class)->roster($version)
        ->first(fn ($r) => $r->teacher->id === $teacher->id);

    expect($row->school->id)->toBe($schoolA->id);
});

test('roster shows the root organization\'s membership_expires_at and ignores a membership in an unrelated organization', function () {
    $organization = Organization::factory()->create();
    $unrelatedOrganization = Organization::factory()->create();
    $version = makeInvitationVersion($organization);

    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();
    attachTeacherToVersionSchool($teacher, $school);

    // A teacher can only have one Membership row per organization (unique
    // constraint on teacher_id+organization_id), so "latest across records"
    // only ever resolves multiple candidates when more than one org is in
    // play — here, an unrelated org's (later) expiry must not win.
    Membership::factory()->create([
        'teacher_id' => $teacher->id,
        'organization_id' => $organization->id,
        'membership_expires_at' => now()->addYear()->format('Y-m-d'),
    ]);
    Membership::factory()->create([
        'teacher_id' => $teacher->id,
        'organization_id' => $unrelatedOrganization->id,
        'membership_expires_at' => now()->addYears(5)->format('Y-m-d'),
    ]);

    $row = app(VersionInvitationEligibilityService::class)->roster($version)
        ->first(fn ($r) => $r->teacher->id === $teacher->id);

    expect($row->membershipExpiresAt->format('Y-m-d'))->toBe(now()->addYear()->format('Y-m-d'));
});
