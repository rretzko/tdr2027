<?php

declare(strict_types=1);

use App\Livewire\Events\Reports\ParticipationByCounty;
use App\Models\Candidate;
use App\Models\CoRegistrationManagerCounty;
use App\Models\County;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

test('mount aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    Livewire::actingAs($user)
        ->test(ParticipationByCounty::class, ['version' => $version])
        ->assertStatus(403);
});

test('includes a county with zero candidates when it is one of the Version\'s configured counties', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $emptyCounty = County::factory()->create(['name' => 'Nobody Here County']);
    $version->counties()->create(['county_id' => $emptyCounty->id]);

    Livewire::actingAs($founder)
        ->test(ParticipationByCounty::class, ['version' => $version])
        ->assertOk()
        ->assertSee('Nobody Here County')
        ->assertSee('0');
});

test('computes candidate and participating-teacher counts for a county with registered candidates', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $county = County::factory()->create();
    $school = School::factory()->create(['county_id' => $county->id]);
    $version->counties()->create(['county_id' => $county->id]);
    $teacher = Teacher::factory()->create();

    Candidate::factory()->registered()->count(2)->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
    ]);

    Livewire::actingAs($founder)
        ->test(ParticipationByCounty::class, ['version' => $version])
        ->assertOk()
        ->assertSee($county->name)
        ->assertSeeInOrder([$county->name, '2']);
});

test('shows the Co-Registration Manager\'s name for a county with one assigned', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $county = County::factory()->create();
    $version->counties()->create(['county_id' => $county->id]);

    $coRegManager = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Overseer']);
    grantVersionRole($coRegManager, $version, 'Co-Registration Manager');
    CoRegistrationManagerCounty::create(['version_id' => $version->id, 'user_id' => $coRegManager->id, 'county_id' => $county->id]);

    Livewire::actingAs($founder)
        ->test(ParticipationByCounty::class, ['version' => $version])
        ->assertOk()
        ->assertSee('Pat Overseer');
});

test('falls back to the Registration Manager\'s name for a county with no Co-Registration Manager assigned', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $county = County::factory()->create();
    $version->counties()->create(['county_id' => $county->id]);

    $registrationManager = User::factory()->create(['first_name' => 'Sam', 'last_name' => 'Statewide']);
    grantVersionRole($registrationManager, $version, 'Registration Manager');

    Livewire::actingAs($founder)
        ->test(ParticipationByCounty::class, ['version' => $version])
        ->assertOk()
        ->assertSee('Sam Statewide');
});

test('a Co-Registration Manager only sees their own assigned counties', function () {
    actingAs(makeFounder());
    $version = Version::factory()->create();
    $countyA = County::factory()->create();
    $countyB = County::factory()->create();
    $version->counties()->create(['county_id' => $countyA->id]);
    $version->counties()->create(['county_id' => $countyB->id]);

    $coRegManager = User::factory()->create();
    grantVersionRole($coRegManager, $version, 'Co-Registration Manager');
    CoRegistrationManagerCounty::create(['version_id' => $version->id, 'user_id' => $coRegManager->id, 'county_id' => $countyA->id]);

    Livewire::actingAs($coRegManager)
        ->test(ParticipationByCounty::class, ['version' => $version])
        ->assertOk()
        ->assertSee($countyA->name)
        ->assertDontSee($countyB->name);
});

test('when the Version has no configured counties, the universe derives from candidate school counties', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $county = County::factory()->create();
    $school = School::factory()->create(['county_id' => $county->id]);
    $teacher = Teacher::factory()->create();

    Candidate::factory()->registered()->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
    ]);

    Livewire::actingAs($founder)
        ->test(ParticipationByCounty::class, ['version' => $version])
        ->assertOk()
        ->assertSee($county->name);
});

test('shows a Totals row at the bottom of the table', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $county = County::factory()->create();
    $school = School::factory()->create(['county_id' => $county->id]);
    $version->counties()->create(['county_id' => $county->id]);
    $teacher = Teacher::factory()->create();

    Candidate::factory()->registered()->count(3)->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
    ]);

    Livewire::actingAs($founder)
        ->test(ParticipationByCounty::class, ['version' => $version])
        ->assertOk()
        ->assertSeeInOrder([$county->name, 'Totals']);
});

test('totals() sums candidateCount across counties but does not double-count a teacher active in more than one county', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();

    $countyA = County::factory()->create();
    $countyB = County::factory()->create();
    $schoolA = School::factory()->create(['county_id' => $countyA->id]);
    $schoolB = School::factory()->create(['county_id' => $countyB->id]);
    $version->counties()->create(['county_id' => $countyA->id]);
    $version->counties()->create(['county_id' => $countyB->id]);

    $teacher = Teacher::factory()->create();

    Candidate::factory()->registered()->create([
        'version_id' => $version->id,
        'school_id' => $schoolA->id,
        'teacher_id' => $teacher->id,
    ]);
    Candidate::factory()->registered()->create([
        'version_id' => $version->id,
        'school_id' => $schoolB->id,
        'teacher_id' => $teacher->id,
    ]);

    $rows = ParticipationByCounty::baseRows($version, null, app(VersionRoleAssignmentService::class));
    $totals = ParticipationByCounty::totals($version, $rows);

    expect($totals['candidateCount'])->toBe(2);
    expect($totals['participatingTeacherCount'])->toBe(1);
});

test('PDF export returns a PDF for an authorized user', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();

    get(route('events.versions.reports.participation-by-county.export', ['version' => $version, 'format' => 'pdf']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('PDF export renders successfully with a Totals row when counties have data', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $county = County::factory()->create();
    $school = School::factory()->create(['county_id' => $county->id]);
    $version->counties()->create(['county_id' => $county->id]);
    $teacher = Teacher::factory()->create();

    Candidate::factory()->registered()->count(2)->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
    ]);

    get(route('events.versions.reports.participation-by-county.export', ['version' => $version, 'format' => 'pdf']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('CSV export returns a CSV for an authorized user', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $county = County::factory()->create();
    $version->counties()->create(['county_id' => $county->id]);

    $response = get(route('events.versions.reports.participation-by-county.export', ['version' => $version, 'format' => 'csv']));

    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    expect($response->streamedContent())->toContain($county->name);
});

test('export aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    actingAs($user);

    get(route('events.versions.reports.participation-by-county.export', ['version' => $version, 'format' => 'pdf']))
        ->assertForbidden();
});
