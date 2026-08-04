<?php

declare(strict_types=1);

use App\Livewire\Events\Reports\CandidateCounts;
use App\Models\Candidate;
use App\Models\CoRegistrationManagerCounty;
use App\Models\County;
use App\Models\Ensemble;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

function makeCandidateCountsVoicePart(Version $version): VoicePart
{
    $ensemble = Ensemble::factory()->create(['event_id' => $version->event_id]);
    $voicePart = VoicePart::factory()->create();
    $ensemble->voiceParts()->attach($voicePart->id);

    return $voicePart;
}

test('mount aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    Livewire::actingAs($user)
        ->test(CandidateCounts::class, ['version' => $version])
        ->assertStatus(403);
});

test('shows the correct per-voice-part and total counts for a school/teacher row', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $school = School::factory()->create();
    $teacher = Teacher::factory()->create();
    $sopranoPart = makeCandidateCountsVoicePart($version);
    $altoPart = makeCandidateCountsVoicePart($version);

    Candidate::factory()->registered()->count(2)->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'voice_part_id' => $sopranoPart->id,
    ]);
    Candidate::factory()->registered()->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'voice_part_id' => $altoPart->id,
    ]);

    Livewire::actingAs($founder)
        ->test(CandidateCounts::class, ['version' => $version])
        ->assertOk()
        ->assertSee($school->name)
        ->assertSee($teacher->user->name)
        ->assertSee($sopranoPart->name.': 2')
        ->assertSee($altoPart->name.': 1')
        ->assertSee('Total: 3');
});

test('a Co-Registration Manager only sees candidate counts within their assigned county', function () {
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
        ->test(CandidateCounts::class, ['version' => $version])
        ->assertOk()
        ->assertSee($schoolA->name)
        ->assertDontSee($schoolB->name);
});

test('search filters rows by school or teacher name', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $matchSchool = School::factory()->create(['name' => 'Lakeview Academy']);
    $matchTeacher = Teacher::factory()->create();
    Candidate::factory()->registered()->create(['version_id' => $version->id, 'school_id' => $matchSchool->id, 'teacher_id' => $matchTeacher->id]);

    $otherSchool = School::factory()->create();
    $otherTeacher = Teacher::factory()->create();
    Candidate::factory()->registered()->create(['version_id' => $version->id, 'school_id' => $otherSchool->id, 'teacher_id' => $otherTeacher->id]);

    Livewire::actingAs($founder)
        ->test(CandidateCounts::class, ['version' => $version])
        ->set('search', 'lakeview')
        ->assertSee($matchTeacher->user->name)
        ->assertDontSee($otherTeacher->user->name);
});

test('PDF export returns a PDF for an authorized user', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();

    get(route('events.versions.reports.candidate-counts.export', ['version' => $version, 'format' => 'pdf']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('CSV export returns a CSV for an authorized user', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $school = School::factory()->create();
    $teacher = Teacher::factory()->create();
    Candidate::factory()->registered()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id]);

    $response = get(route('events.versions.reports.candidate-counts.export', ['version' => $version, 'format' => 'csv']));

    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    expect($response->streamedContent())->toContain($school->name);
});

test('export aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    actingAs($user);

    get(route('events.versions.reports.candidate-counts.export', ['version' => $version, 'format' => 'pdf']))
        ->assertForbidden();
});
