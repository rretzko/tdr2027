<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\CandidateStatusHistory;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VoicePart;
use App\Services\CandidateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('enroll creates an eligible Candidate tied to the student, version, school, teacher, and voice part', function () {
    actingAs(User::factory()->create());

    $version = Version::factory()->create();
    $student = Student::factory()->create();
    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();
    $voicePart = VoicePart::factory()->create();

    $candidate = (new CandidateService)->enroll($version, $student, $teacher, $school->id, $voicePart->id);

    expect($candidate->student_id)->toBe($student->id);
    expect($candidate->version_id)->toBe($version->id);
    expect($candidate->school_id)->toBe($school->id);
    expect($candidate->teacher_id)->toBe($teacher->id);
    expect($candidate->voice_part_id)->toBe($voicePart->id);
    expect($candidate->status)->toBe(CandidateStatus::Eligible);
});

test('withdraw sets the Candidate status to teacher_withdrawn', function () {
    actingAs(User::factory()->create());

    $candidate = Candidate::factory()->create(['status' => CandidateStatus::Registered]);

    (new CandidateService)->withdraw($candidate);

    expect($candidate->refresh()->status)->toBe(CandidateStatus::TeacherWithdrawn);
});

test('recalculateStatus leaves an eligible Candidate eligible when no checklist items are done', function () {
    actingAs(User::factory()->create());

    $candidate = Candidate::factory()->create(['status' => CandidateStatus::Eligible]);

    (new CandidateService)->recalculateStatus($candidate, [
        ['label' => 'Item one', 'check' => fn (Candidate $c): bool => false],
        ['label' => 'Item two', 'check' => fn (Candidate $c): bool => false],
    ]);

    expect($candidate->refresh()->status)->toBe(CandidateStatus::Eligible);
});

test('recalculateStatus promotes to pending when some but not all checklist items are done', function () {
    actingAs(User::factory()->create());

    $candidate = Candidate::factory()->create(['status' => CandidateStatus::Eligible]);

    (new CandidateService)->recalculateStatus($candidate, [
        ['label' => 'Item one', 'check' => fn (Candidate $c): bool => true],
        ['label' => 'Item two', 'check' => fn (Candidate $c): bool => false],
    ]);

    expect($candidate->refresh()->status)->toBe(CandidateStatus::Pending);
});

test('recalculateStatus promotes to registered when all checklist items are done', function () {
    actingAs(User::factory()->create());

    $candidate = Candidate::factory()->create(['status' => CandidateStatus::Eligible]);

    (new CandidateService)->recalculateStatus($candidate, [
        ['label' => 'Item one', 'check' => fn (Candidate $c): bool => true],
        ['label' => 'Item two', 'check' => fn (Candidate $c): bool => true],
    ]);

    expect($candidate->refresh()->status)->toBe(CandidateStatus::Registered);
});

test('recalculateStatus promotes to registered when the checklist is empty', function () {
    actingAs(User::factory()->create());

    $candidate = Candidate::factory()->create(['status' => CandidateStatus::Eligible]);

    (new CandidateService)->recalculateStatus($candidate, []);

    expect($candidate->refresh()->status)->toBe(CandidateStatus::Registered);
});

test('recalculateStatus demotes a pending Candidate back to eligible when checklist items regress', function () {
    actingAs(User::factory()->create());

    $candidate = Candidate::factory()->create(['status' => CandidateStatus::Pending]);

    (new CandidateService)->recalculateStatus($candidate, [
        ['label' => 'Item one', 'check' => fn (Candidate $c): bool => false],
    ]);

    expect($candidate->refresh()->status)->toBe(CandidateStatus::Eligible);
});

test('recalculateStatus does not touch a Candidate that has already withdrawn', function () {
    actingAs(User::factory()->create());

    $candidate = Candidate::factory()->create(['status' => CandidateStatus::TeacherWithdrawn]);

    (new CandidateService)->recalculateStatus($candidate, [
        ['label' => 'Item one', 'check' => fn (Candidate $c): bool => true],
    ]);

    expect($candidate->refresh()->status)->toBe(CandidateStatus::TeacherWithdrawn);
});

test('recalculateStatus does not record a history entry when the status does not actually change', function () {
    actingAs(User::factory()->create());

    $candidate = Candidate::factory()->create(['status' => CandidateStatus::Registered]);

    (new CandidateService)->recalculateStatus($candidate, [
        ['label' => 'Item one', 'check' => fn (Candidate $c): bool => true],
    ]);

    expect(CandidateStatusHistory::where('candidate_id', $candidate->id)->count())->toBe(1);
});
