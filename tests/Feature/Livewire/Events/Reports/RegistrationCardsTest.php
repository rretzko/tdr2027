<?php

declare(strict_types=1);

use App\Livewire\Events\Reports\RegistrationCards;
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

test('mount aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    Livewire::actingAs($user)
        ->test(RegistrationCards::class, ['version' => $version])
        ->assertStatus(403);
});

test('shows the placeholder notice and school filter options', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $school = School::factory()->create();
    $teacher = Teacher::factory()->create();
    Candidate::factory()->registered()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($founder)
        ->test(RegistrationCards::class, ['version' => $version])
        ->assertOk()
        ->assertSee('No in-person audition is scheduled')
        ->assertSee($school->name);
});

test('a Co-Registration Manager only sees school options within their assigned county', function () {
    actingAs(makeFounder());
    $version = Version::factory()->create();
    $countyA = County::factory()->create();
    $countyB = County::factory()->create();
    $schoolA = School::factory()->create(['county_id' => $countyA->id]);
    $schoolB = School::factory()->create(['county_id' => $countyB->id]);
    $teacherA = Teacher::factory()->create();
    $teacherB = Teacher::factory()->create();
    Candidate::factory()->registered()->create(['version_id' => $version->id, 'school_id' => $schoolA->id, 'teacher_id' => $teacherA->id]);
    Candidate::factory()->registered()->create(['version_id' => $version->id, 'school_id' => $schoolB->id, 'teacher_id' => $teacherB->id]);

    $coRegManager = User::factory()->create();
    grantVersionRole($coRegManager, $version, 'Co-Registration Manager');
    CoRegistrationManagerCounty::create(['version_id' => $version->id, 'user_id' => $coRegManager->id, 'county_id' => $countyA->id]);

    Livewire::actingAs($coRegManager)
        ->test(RegistrationCards::class, ['version' => $version])
        ->assertOk()
        ->assertSee($schoolA->name)
        ->assertDontSee($schoolB->name);
});

test('PDF export returns a PDF listing the filtered candidate', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $school = School::factory()->create();
    $teacher = Teacher::factory()->create();
    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id]);

    get(route('events.versions.reports.registration-cards.pdf', ['version' => $version, 'candidateIdFilter' => $candidate->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('PDF export aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    actingAs($user);

    get(route('events.versions.reports.registration-cards.pdf', $version))
        ->assertForbidden();
});
