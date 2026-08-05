<?php

declare(strict_types=1);

use App\Livewire\Events\Reports\ParticipatingTeachers;
use App\Models\Candidate;
use App\Models\CoRegistrationManagerCounty;
use App\Models\County;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/**
 * CandidateObserver::created() writes candidate_status_history.user_id from
 * Auth::id(), which is NOT NULL — callers must actingAs() someone before
 * creating Candidates via this helper (matches CandidateDetailTest's
 * established pattern).
 */
function makeParticipatingTeacher(Version $version, ?County $county = null, int $registeredCount = 1): Teacher
{
    $county ??= County::factory()->create();
    $school = School::factory()->create(['county_id' => $county->id]);
    $teacher = Teacher::factory()->create();

    Candidate::factory()->registered()->count($registeredCount)->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
    ]);

    return $teacher;
}

test('mount aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    Livewire::actingAs($user)
        ->test(ParticipatingTeachers::class, ['version' => $version])
        ->assertStatus(403);
});

test('lists a teacher with registered candidates and shows the correct count', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $teacher = makeParticipatingTeacher($version, registeredCount: 3);

    Livewire::actingAs($founder)
        ->test(ParticipatingTeachers::class, ['version' => $version])
        ->assertOk()
        ->assertSee($teacher->user->name)
        ->assertSee('3');
});

test('does not list a teacher whose candidates are only eligible, not registered', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $school = School::factory()->create();
    $teacher = Teacher::factory()->create();
    Candidate::factory()->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
    ]);

    Livewire::actingAs($founder)
        ->test(ParticipatingTeachers::class, ['version' => $version])
        ->assertOk()
        ->assertDontSee($teacher->user->name);
});

test('a Co-Registration Manager only sees participating teachers within their assigned county', function () {
    actingAs(makeFounder());
    $version = Version::factory()->create();
    $countyA = County::factory()->create();
    $countyB = County::factory()->create();

    $teacherInA = makeParticipatingTeacher($version, $countyA);
    $teacherInB = makeParticipatingTeacher($version, $countyB);

    $coRegManager = User::factory()->create();
    grantVersionRole($coRegManager, $version, 'Co-Registration Manager');
    CoRegistrationManagerCounty::create([
        'version_id' => $version->id,
        'user_id' => $coRegManager->id,
        'county_id' => $countyA->id,
    ]);

    Livewire::actingAs($coRegManager)
        ->test(ParticipatingTeachers::class, ['version' => $version])
        ->assertOk()
        ->assertSee($teacherInA->user->name)
        ->assertDontSee($teacherInB->user->name);
});

test('search filters the roster by teacher or school name', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $school = School::factory()->create(['name' => 'Riverside High School']);
    $teacher = Teacher::factory()->create();
    Candidate::factory()->registered()->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
    ]);

    makeParticipatingTeacher($version);

    Livewire::actingAs($founder)
        ->test(ParticipatingTeachers::class, ['version' => $version])
        ->set('search', 'riverside')
        ->assertSee($teacher->user->name);
});

test('sorting by Registered toggles ascending and descending order', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $teacherFew = makeParticipatingTeacher($version, registeredCount: 1);
    $teacherMany = makeParticipatingTeacher($version, registeredCount: 5);

    $component = Livewire::actingAs($founder)
        ->test(ParticipatingTeachers::class, ['version' => $version])
        ->call('sortBy', 'count');

    $component->assertSeeInOrder([$teacherFew->user->name, $teacherMany->user->name]);

    $component->call('sortBy', 'count');

    $component->assertSeeInOrder([$teacherMany->user->name, $teacherFew->user->name]);
});

test('PDF export returns a PDF for an authorized user', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    makeParticipatingTeacher($version);

    get(route('events.versions.reports.participating-teachers.pdf', $version))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('PDF export aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    actingAs($user);

    get(route('events.versions.reports.participating-teachers.pdf', $version))
        ->assertForbidden();
});
