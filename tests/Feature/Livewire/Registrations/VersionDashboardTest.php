<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\VersionInvitationStatus;
use App\Livewire\Registrations\VersionDashboard;
use App\Models\Candidate;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VoicePart;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeRegistrationTeacher(): Teacher
{
    $user = User::factory()->create();

    return Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
}

function attachEligibleStudentToTeacher(Teacher $teacher, School $school): Student
{
    $student = Student::factory()->create();

    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    $student->schools()->attach($school->id, ['is_active' => true, 'class_of' => (int) date('Y') + 1]);
    $student->teachers()->attach($teacher->id, [
        'school_id' => $school->id,
        'subject' => 'chorus',
        'role' => 'primary',
        'is_active' => true,
    ]);

    return $student;
}

test('mount displays the version name', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create(['name' => 'Fall Auditions']);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertSee('Fall Auditions');
});

test('enroll requires a student and a voice part', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->set('enroll_student_id', '')
        ->set('enroll_voice_part_id', '')
        ->call('enroll')
        ->assertHasErrors(['enroll_student_id', 'enroll_voice_part_id']);
});

test('enroll creates a Candidate for an eligible student and clears the form', function () {
    $teacher = makeRegistrationTeacher();
    $school = School::factory()->create();
    $student = attachEligibleStudentToTeacher($teacher, $school);
    $voicePart = VoicePart::factory()->create();
    $version = Version::factory()->create();

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->set('enroll_student_id', (string) $student->id)
        ->set('enroll_voice_part_id', (string) $voicePart->id)
        ->call('enroll')
        ->assertHasNoErrors()
        ->assertSet('enroll_student_id', '')
        ->assertSet('enroll_voice_part_id', '');

    expect(Candidate::where('version_id', $version->id)->where('student_id', $student->id)->exists())->toBeTrue();
});

test('enroll rejects a student with no school shared with the teacher', function () {
    $teacher = makeRegistrationTeacher();
    $unrelatedStudent = Student::factory()->create();
    $voicePart = VoicePart::factory()->create();
    $version = Version::factory()->create();

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->set('enroll_student_id', (string) $unrelatedStudent->id)
        ->set('enroll_voice_part_id', (string) $voicePart->id)
        ->call('enroll')
        ->assertHasErrors('enroll_student_id');

    expect(Candidate::where('student_id', $unrelatedStudent->id)->exists())->toBeFalse();
});

test('withdraw sets the candidate status to teacher_withdrawn', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Registered,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->call('withdraw', $candidate->id);

    expect($candidate->refresh()->status)->toBe(CandidateStatus::TeacherWithdrawn);
});

test('withdraw cannot target a candidate belonging to another teacher', function () {
    $teacher = makeRegistrationTeacher();
    $otherTeacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($otherTeacher->user);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $otherTeacher->id,
        'status' => CandidateStatus::Registered,
    ]);

    expect(function () use ($teacher, $version, $candidate) {
        Livewire::actingAs($teacher->user)
            ->test(VersionDashboard::class, ['version' => $version])
            ->call('withdraw', $candidate->id);
    })->toThrow(ModelNotFoundException::class);

    expect($candidate->refresh()->status)->toBe(CandidateStatus::Registered);
});

test('enroll is blocked once the teacher has rejected this Version\'s obligations (iron gate)', function () {
    $teacher = makeRegistrationTeacher();
    $school = School::factory()->create();
    $student = attachEligibleStudentToTeacher($teacher, $school);
    $voicePart = VoicePart::factory()->create();
    $version = Version::factory()->create();

    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationStatus::Rejected->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertDontSee('Enroll a Student')
        ->set('enroll_student_id', (string) $student->id)
        ->set('enroll_voice_part_id', (string) $voicePart->id)
        ->call('enroll')
        ->assertHasErrors('enroll_student_id');

    expect(Candidate::where('version_id', $version->id)->where('student_id', $student->id)->exists())->toBeFalse();
});

test('refreshStatus recalculates the candidate status from the checklist', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create(['emergency_contact_name' => true]);
    actingAs($teacher->user);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Eligible,
        'program_name' => 'A Candidate',
    ]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->call('refreshStatus', $candidate->id);

    // program_name is done but emergency contact is not, so the candidate
    // should move from eligible to pending — not stay eligible.
    expect($candidate->refresh()->status)->toBe(CandidateStatus::Pending);
});
