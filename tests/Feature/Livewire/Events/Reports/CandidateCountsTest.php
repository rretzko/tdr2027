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
        ->assertSee($sopranoPart->abbr.': 2')
        ->assertSee($altoPart->abbr.': 1')
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

test('shows the teacher email under the teacher name', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $school = School::factory()->create();
    $teacher = Teacher::factory()->create();
    Candidate::factory()->registered()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($founder)
        ->test(CandidateCounts::class, ['version' => $version])
        ->assertOk()
        ->assertSeeInOrder([$teacher->user->name, $teacher->user->email]);
});

test('shows a Totals row summing every voice-part column and the grand total', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $sopranoPart = makeCandidateCountsVoicePart($version);
    $altoPart = makeCandidateCountsVoicePart($version);

    $schoolA = School::factory()->create();
    $teacherA = Teacher::factory()->create();
    Candidate::factory()->registered()->count(2)->create([
        'version_id' => $version->id,
        'school_id' => $schoolA->id,
        'teacher_id' => $teacherA->id,
        'voice_part_id' => $sopranoPart->id,
    ]);

    $schoolB = School::factory()->create();
    $teacherB = Teacher::factory()->create();
    Candidate::factory()->registered()->create([
        'version_id' => $version->id,
        'school_id' => $schoolB->id,
        'teacher_id' => $teacherB->id,
        'voice_part_id' => $altoPart->id,
    ]);

    Livewire::actingAs($founder)
        ->test(CandidateCounts::class, ['version' => $version])
        ->assertOk()
        ->assertSeeInOrder([$schoolB->name, 'Totals']);
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

test('CSV export includes Email and Phone columns and abbr-based voice-part headers', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $voicePart = makeCandidateCountsVoicePart($version);
    $school = School::factory()->create();
    $teacher = Teacher::factory()->create();
    $teacher->user->update(['cell_phone' => '5551234567']);
    Candidate::factory()->registered()->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'voice_part_id' => $voicePart->id,
    ]);

    $response = get(route('events.versions.reports.candidate-counts.export', ['version' => $version, 'format' => 'csv']));

    $response->assertOk();
    $content = $response->streamedContent();
    $headerLine = strtok($content, "\n");

    expect($content)->toContain('Email');
    expect($content)->toContain($teacher->user->email);
    expect($content)->toContain('Phone');
    expect($content)->toContain('(555) 123-4567');
    // Asserted as an exact header line, not a substring search for
    // $voicePart->name — that was flaky, since VoicePartFactory's name is a
    // random fake()->word() that can itself be (or be a substring of) one of
    // the fixed column labels below (e.g. "a" inside "School"/"Teacher"),
    // failing the "not contain the name" check for reasons having nothing to
    // do with whether the header actually used the abbreviation.
    expect($headerLine)->toBe("School,Teacher,Email,Phone,{$voicePart->abbr},Total");
});

test('export aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    actingAs($user);

    get(route('events.versions.reports.candidate-counts.export', ['version' => $version, 'format' => 'pdf']))
        ->assertForbidden();
});
