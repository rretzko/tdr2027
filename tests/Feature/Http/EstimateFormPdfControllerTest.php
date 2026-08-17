<?php

declare(strict_types=1);

use App\Models\Candidate;
use App\Models\County;
use App\Models\Membership;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionFee;
use App\Models\VersionMailToAddress;
use App\Models\VersionMembershipRequirement;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/**
 * @return array{teacher: Teacher, version: Version, school: School}
 */
function makeEstimateFormPdfScenario(): array
{
    $teacherUser = User::factory()->create();
    $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id, 'onboarding_completed_at' => now()]);
    $school = School::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);

    $version = Version::factory()->create();
    VersionFee::create(['version_id' => $version->id, 'registration' => 3000]);

    // CandidateObserver writes candidate_status_history rows keyed to the
    // acting user — must have someone authenticated before creating, same
    // pattern as makeRegistrationsScoreReportScenario().
    actingAs($teacherUser);
    Candidate::factory()->registered()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id]);

    return compact('teacher', 'version', 'school');
}

test('renders a PDF for the requesting teacher\'s own registered candidates at that school', function () {
    ['teacher' => $teacher, 'version' => $version, 'school' => $school] = makeEstimateFormPdfScenario();

    actingAs($teacher->user);

    get(route('registrations.estimate-form-pdf', [$version, $school]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('403s when the teacher is not active+verified at that school', function () {
    ['version' => $version] = makeEstimateFormPdfScenario();
    $otherTeacherUser = User::factory()->create();
    $otherTeacher = Teacher::factory()->create(['user_id' => $otherTeacherUser->id, 'onboarding_completed_at' => now()]);
    // A different active+verified school, so the request clears the
    // has.active.school middleware and reaches the controller's own check.
    $otherTeacher->schools()->attach(School::factory()->create()->id, ['is_active' => true, 'verified_at' => now()]);
    $otherSchool = School::factory()->create();

    actingAs($otherTeacher->user);

    get(route('registrations.estimate-form-pdf', [$version, $otherSchool]))
        ->assertStatus(403);
});

test('403s for a user with no Teacher record', function () {
    ['version' => $version, 'school' => $school] = makeEstimateFormPdfScenario();
    $nonTeacher = User::factory()->create();

    actingAs($nonTeacher);

    get(route('registrations.estimate-form-pdf', [$version, $school]))
        ->assertStatus(403);
});

test('renders the Membership Card placeholder page when required and no card image is on file', function () {
    ['teacher' => $teacher, 'version' => $version, 'school' => $school] = makeEstimateFormPdfScenario();
    VersionMembershipRequirement::create(['version_id' => $version->id, 'membership_card' => true]);

    actingAs($teacher->user);

    get(route('registrations.estimate-form-pdf', [$version, $school]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('renders the Membership Card image when one is on file for the teacher at the Event\'s root organization', function () {
    Storage::fake('s3');
    ['teacher' => $teacher, 'version' => $version, 'school' => $school] = makeEstimateFormPdfScenario();
    VersionMembershipRequirement::create(['version_id' => $version->id, 'membership_card' => true]);

    Membership::factory()->create([
        'teacher_id' => $teacher->id,
        'organization_id' => $version->event->organization_id,
        'membership_card' => 'membership-cards/card.png',
    ]);

    actingAs($teacher->user);

    get(route('registrations.estimate-form-pdf', [$version, $school]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('renders the Mail-To page via the county-based Co-Registration Manager fallback', function () {
    ['teacher' => $teacher, 'version' => $version, 'school' => $school] = makeEstimateFormPdfScenario();
    $founder = makeFounder();

    $county = County::factory()->create();
    $school->update(['county_id' => $county->id]);
    $version->counties()->create(['county_id' => $county->id]);

    $coManager = User::factory()->create();
    app(VersionRoleAssignmentService::class)->assignCoRegistrationManager($founder, $version, $coManager, [$county->id]);
    VersionMailToAddress::factory()->create(['version_id' => $version->id, 'user_id' => $coManager->id]);

    actingAs($teacher->user);

    get(route('registrations.estimate-form-pdf', [$version, $school]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('renders the Mail-To placeholder when no manager address is configured', function () {
    ['teacher' => $teacher, 'version' => $version, 'school' => $school] = makeEstimateFormPdfScenario();

    actingAs($teacher->user);

    get(route('registrations.estimate-form-pdf', [$version, $school]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
