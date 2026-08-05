<?php

declare(strict_types=1);

use App\Enums\ObligationDecision;
use App\Enums\VersionInvitationStatus;
use App\Enums\VersionObligationStatus;
use App\Livewire\Events\Reports\ObligatedTeachers;
use App\Models\CoRegistrationManagerCounty;
use App\Models\County;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VersionObligation;
use App\Models\VersionObligationResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/**
 * Builds a teacher with an active+verified school in $county, invited to
 * $version, having accepted its (published) obligations — i.e. a row that
 * should appear on the Obligated Teachers report.
 */
function makeObligatedTeacher(Version $version, ?County $county = null): Teacher
{
    $county ??= County::factory()->create();
    $school = School::factory()->create(['county_id' => $county->id]);
    $teacher = Teacher::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);

    $invitation = VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    $obligation = VersionObligation::firstOrCreate(
        ['version_id' => $version->id],
        [
            'body' => '<p>Be excellent.</p>',
            'status' => VersionObligationStatus::Published->value,
            'published_at' => now(),
            'published_by_user_id' => User::factory()->create()->id,
        ],
    );

    VersionObligationResponse::create([
        'version_invitation_id' => $invitation->id,
        'version_obligation_id' => $obligation->id,
        'decision' => ObligationDecision::Accepted->value,
        'decided_at' => now(),
        'obligation_snapshot' => $obligation->body,
    ]);

    return $teacher;
}

test('mount aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    Livewire::actingAs($user)
        ->test(ObligatedTeachers::class, ['version' => $version])
        ->assertStatus(403);
});

test('lists a teacher who has accepted the published obligations', function () {
    $founder = makeFounder();
    $version = Version::factory()->create();
    $teacher = makeObligatedTeacher($version);

    Livewire::actingAs($founder)
        ->test(ObligatedTeachers::class, ['version' => $version])
        ->assertOk()
        ->assertSee($teacher->user->name);
});

test('does not list an invited teacher who has not accepted the obligations', function () {
    $founder = makeFounder();
    $version = Version::factory()->create();
    $teacher = Teacher::factory()->create();

    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    Livewire::actingAs($founder)
        ->test(ObligatedTeachers::class, ['version' => $version])
        ->assertOk()
        ->assertDontSee($teacher->user->name);
});

test('a Co-Registration Manager only sees obligated teachers within their assigned county', function () {
    $version = Version::factory()->create();
    $countyA = County::factory()->create();
    $countyB = County::factory()->create();

    $teacherInA = makeObligatedTeacher($version, $countyA);
    $teacherInB = makeObligatedTeacher($version, $countyB);

    $coRegManager = User::factory()->create();
    grantVersionRole($coRegManager, $version, 'Co-Registration Manager');
    CoRegistrationManagerCounty::create([
        'version_id' => $version->id,
        'user_id' => $coRegManager->id,
        'county_id' => $countyA->id,
    ]);

    Livewire::actingAs($coRegManager)
        ->test(ObligatedTeachers::class, ['version' => $version])
        ->assertOk()
        ->assertSee($teacherInA->user->name)
        ->assertDontSee($teacherInB->user->name);
});

test('a Registration Manager sees obligated teachers across every county', function () {
    $version = Version::factory()->create();
    $countyA = County::factory()->create();
    $countyB = County::factory()->create();

    $teacherInA = makeObligatedTeacher($version, $countyA);
    $teacherInB = makeObligatedTeacher($version, $countyB);

    $registrationManager = User::factory()->create();
    grantVersionRole($registrationManager, $version, 'Registration Manager');

    Livewire::actingAs($registrationManager)
        ->test(ObligatedTeachers::class, ['version' => $version])
        ->assertOk()
        ->assertSee($teacherInA->user->name)
        ->assertSee($teacherInB->user->name);
});

test('search filters the roster by teacher name', function () {
    $founder = makeFounder();
    $version = Version::factory()->create();
    $teacher = makeObligatedTeacher($version);
    $teacher->user->update(['first_name' => 'Zelda', 'last_name' => 'Ziegler']);

    $otherTeacher = makeObligatedTeacher($version);
    $otherTeacher->user->update(['first_name' => 'Amy', 'last_name' => 'Adams']);

    Livewire::actingAs($founder)
        ->test(ObligatedTeachers::class, ['version' => $version])
        ->set('search', 'zelda')
        ->assertSee('Zelda Ziegler')
        ->assertDontSee('Amy Adams');
});

test('PDF export aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    actingAs($user);

    get(route('events.versions.reports.obligated-teachers.pdf', $version))
        ->assertForbidden();
});

test('PDF export returns a PDF for an authorized user', function () {
    $founder = makeFounder();
    $version = Version::factory()->create();
    makeObligatedTeacher($version);

    actingAs($founder);

    get(route('events.versions.reports.obligated-teachers.pdf', $version))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});
