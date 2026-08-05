<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Organization;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeWebRegImpersonationVersion(): Version
{
    $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    return Version::factory()->create(['event_id' => $event->id]);
}

function webRegImpersonationSession(int $managerId, int $versionId): array
{
    return [
        'impersonator_id' => $managerId,
        'impersonation_scope' => 'web_registration_manager',
        'impersonation_version_id' => $versionId,
    ];
}

test('a Web-Registration-Manager-impersonated session cannot reach the teacher profile page', function () {
    $manager = User::factory()->create();
    $version = makeWebRegImpersonationVersion();
    $teacherUser = User::factory()->create();
    Teacher::factory()->create(['user_id' => $teacherUser->id]);

    actingAs($teacherUser)
        ->withSession(webRegImpersonationSession($manager->id, $version->id))
        ->get(route('settings.profile'))
        ->assertStatus(403);
});

test('a Web-Registration-Manager-impersonated session cannot reach a different Version\'s Registrations dashboard', function () {
    $manager = User::factory()->create();
    $lockedVersion = makeWebRegImpersonationVersion();
    $otherVersion = makeWebRegImpersonationVersion();

    $teacherUser = User::factory()->create();
    $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id, 'onboarding_completed_at' => now()]);
    VersionInvitation::create([
        'version_id' => $otherVersion->id,
        'teacher_id' => $teacher->id,
        'status' => 'invited',
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    actingAs($teacherUser)
        ->withSession(webRegImpersonationSession($manager->id, $lockedVersion->id))
        ->get(route('registrations.version', $otherVersion))
        ->assertStatus(403);
});

test('a Web-Registration-Manager-impersonated session can reach the locked Version\'s Registrations dashboard', function () {
    $manager = User::factory()->create();
    $version = makeWebRegImpersonationVersion();

    $teacherUser = User::factory()->create();
    $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id, 'onboarding_completed_at' => now()]);
    $school = School::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => 'invited',
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    actingAs($teacherUser)
        ->withSession(webRegImpersonationSession($manager->id, $version->id))
        ->get(route('registrations.version', $version))
        ->assertOk();
});

test('a Founder-impersonated session (no impersonation_scope) is unaffected by the new restriction', function () {
    $founder = makeFounder();
    $teacherUser = User::factory()->create();
    Teacher::factory()->create(['user_id' => $teacherUser->id, 'onboarding_completed_at' => now()]);

    actingAs($teacherUser)
        ->withSession(['impersonator_id' => $founder->id])
        ->get(route('settings.profile'))
        ->assertOk();
});

test('stopping a Web-Registration-Manager-initiated impersonation returns to the Web Registration screen, not the Founder impersonate page', function () {
    $manager = User::factory()->create();
    $version = makeWebRegImpersonationVersion();
    grantVersionRole($manager, $version, 'Web Registration Manager');

    $teacherUser = User::factory()->create();
    Teacher::factory()->create(['user_id' => $teacherUser->id, 'onboarding_completed_at' => now()]);

    actingAs($teacherUser)
        ->withSession(webRegImpersonationSession($manager->id, $version->id))
        ->post(route('founder.stop-impersonating'))
        ->assertRedirect(route('events.versions.web-registration', $version));

    expect(auth()->id())->toBe($manager->id);
    expect(session()->has('impersonator_id'))->toBeFalse();
    expect(session()->has('impersonation_scope'))->toBeFalse();
    expect(session()->has('impersonation_version_id'))->toBeFalse();
});
