<?php

declare(strict_types=1);

use App\Livewire\Founder\StudentFolio;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\EventGrade;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VoicePart;
use App\Services\StudentFolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

// Helpers are folio-prefixed: Pest test-file functions are global across the
// suite, so reusing another file's helper names would fatal-error a full run.

/** @return array{0: Teacher, 1: School, 2: Student} */
function folioFixture(bool $teacherVerified = true): array
{
    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => $teacherVerified ? now() : null]);

    $student = Student::factory()->create();
    $student->schools()->attach($school->id, ['is_active' => true, 'class_of' => (int) date('Y') + 1]);
    $student->teachers()->attach($teacher->id, [
        'school_id' => $school->id,
        'subject' => 'chorus',
        'role' => 'primary',
        'is_active' => true,
    ]);

    return [$teacher, $school, $student];
}

function folioActiveVersion(): Version
{
    $version = Version::factory()->active()->create(['event_id' => Event::factory()->create()->id]);
    $voicePart = VoicePart::factory()->create(['sort_order' => 1]);
    Ensemble::factory()->create(['event_id' => $version->event_id])->voiceParts()->attach($voicePart->id);

    return $version;
}

function folioInvite(Teacher $teacher, Version $version, string $status = 'invited'): void
{
    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => $status,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * @param  list<array{version: Version, candidate: ?Candidate, teachers: list<array{teacher: Teacher, checks: list<array{label: string, passed: bool, detail: string}>, eligible: bool}>}>  $diagnostics
 * @return array<string, bool>
 */
function folioChecksFor(array $diagnostics, Version $version): array
{
    $entry = collect($diagnostics)->first(fn (array $d): bool => $d['version']->id === $version->id);

    return collect($entry['teachers'][0]['checks'])->mapWithKeys(fn (array $c): array => [$c['label'] => $c['passed']])->all();
}

test('a non-founder cannot view the student folio', function () {
    actingAs(User::factory()->create())->get(route('founder.student-folio'))->assertNotFound();
});

test('the founder can view the student folio page', function () {
    actingAs(makeFounderUser())->get(route('founder.student-folio'))->assertOk();
});

test('search finds a student by email', function () {
    [, , $student] = folioFixture();
    $student->user->update(['email' => 'findme@example.test']);

    Livewire::actingAs(makeFounderUser())
        ->test(StudentFolio::class)
        ->set('search', 'findme@')
        ->assertSee('findme@example.test');
});

test('selecting a student renders their school and teacher', function () {
    [$teacher, $school, $student] = folioFixture();

    Livewire::actingAs(makeFounderUser())
        ->test(StudentFolio::class)
        ->call('select', $student->id)
        ->assertSee($school->name)
        ->assertSee($teacher->user->email)
        ->assertSee('On roster');
});

test('roster visibility flags a link hidden because the teacher is unverified at the school', function () {
    [, , $student] = folioFixture(teacherVerified: false);

    $rows = (new StudentFolioService)->rosterVisibility($student);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['visible'])->toBeFalse()
        ->and($rows[0]['reason'])->toContain('not yet verified');
});

test('version diagnostics fail the invitation check when the teacher is not invited', function () {
    [, , $student] = folioFixture();
    $version = folioActiveVersion();

    $checks = folioChecksFor((new StudentFolioService)->versionDiagnostics($student), $version);

    expect($checks['Teacher invited to this version'])->toBeFalse();
});

test('version diagnostics fail the grade check when the event excludes the student\'s grade', function () {
    [$teacher, , $student] = folioFixture();
    $version = folioActiveVersion();
    EventGrade::create(['event_id' => $version->event_id, 'grade' => 4]);
    folioInvite($teacher, $version);

    $checks = folioChecksFor((new StudentFolioService)->versionDiagnostics($student->fresh()), $version);

    expect($checks['Teacher invited to this version'])->toBeTrue()
        ->and($checks['Grade allowed by event'])->toBeFalse();
});

test('version diagnostics flag rejected obligations', function () {
    [$teacher, , $student] = folioFixture();
    $version = folioActiveVersion();
    folioInvite($teacher, $version, 'rejected');

    $checks = folioChecksFor((new StudentFolioService)->versionDiagnostics($student), $version);

    expect($checks['Obligations not rejected'])->toBeFalse();
});

test('an eligible student shows every gate passing and their candidate', function () {
    [$teacher, , $student] = folioFixture();
    $version = folioActiveVersion();
    actingAs($teacher->user);
    folioInvite($teacher, $version);

    $diagnostics = (new StudentFolioService)->versionDiagnostics($student->fresh());
    $entry = collect($diagnostics)->first(fn (array $d): bool => $d['version']->id === $version->id);

    expect($entry['candidate'])->not->toBeNull()
        ->and($entry['teachers'][0]['eligible'])->toBeTrue();
});

test('reEnroll creates a missing candidate when every gate passes', function () {
    [$teacher, , $student] = folioFixture();
    $version = folioActiveVersion();
    actingAs($teacher->user);
    folioInvite($teacher, $version);

    // Simulate the observer never having fired.
    DB::table('candidates')->where('student_id', $student->id)->delete();

    Livewire::actingAs(makeFounderUser())
        ->test(StudentFolio::class)
        ->call('select', $student->id)
        ->call('reEnroll');

    expect(Candidate::where('student_id', $student->id)->where('version_id', $version->id)->exists())->toBeTrue();
});
