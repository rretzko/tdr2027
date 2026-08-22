<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\VersionInvitationStatus;
use App\Livewire\Sfdi\School;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Pivots\SchoolStudent;
use App\Models\Pivots\StudentTeacher;
use App\Models\SchoolGrade;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeSfdiStudent(): User
{
    $user = User::factory()->create();
    Student::factory()->create(['user_id' => $user->id]);

    return $user;
}

function makeVerifiedTeacherAt(App\Models\School $school): Teacher
{
    $teacherUser = User::factory()->create();
    $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id, 'onboarding_completed_at' => now()]);
    $teacher->schools()->attach($school->id, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);

    return $teacher;
}

test('a student can search for and join an existing school with a teacher and subject', function () {
    $studentUser = makeSfdiStudent();
    $school = App\Models\School::factory()->create(['name' => 'Central High School']);
    SchoolGrade::factory()->create(['school_id' => $school->id, 'grade' => 9]);
    $teacher = makeVerifiedTeacherAt($school);

    Livewire::actingAs($studentUser)
        ->test(School::class)
        ->set('school_search', 'Central')
        ->call('selectSchool', $school->id)
        ->set('grade', 9)
        ->set("teacherSubjects.{$teacher->id}", ['chorus'])
        ->call('join')
        ->assertRedirect(route('dashboard'));

    $student = $studentUser->student;

    $schoolStudent = SchoolStudent::where('student_id', $student->id)->where('school_id', $school->id)->first();

    expect($schoolStudent)->not->toBeNull();
    expect($schoolStudent->is_active)->toBeTrue();

    expect(StudentTeacher::where('student_id', $student->id)
        ->where('teacher_id', $teacher->id)
        ->where('school_id', $school->id)
        ->where('subject', 'chorus')
        ->exists())->toBeTrue();
});

test('grade options are always 4-12 regardless of whether the school has SchoolGrade rows configured', function () {
    $studentUser = makeSfdiStudent();
    $school = App\Models\School::factory()->create();
    // Deliberately no SchoolGrade::factory() row for this school.

    Livewire::actingAs($studentUser)
        ->test(School::class)
        ->call('selectSchool', $school->id)
        ->assertSet('grade', '')
        ->assertViewHas('gradeOptions', [4, 5, 6, 7, 8, 9, 10, 11, 12]);
});

test('joining a school auto-enrolls the student into an already-open eligible version', function () {
    $studentUser = makeSfdiStudent();
    $school = App\Models\School::factory()->create();
    SchoolGrade::factory()->create(['school_id' => $school->id, 'grade' => 9]);
    $teacher = makeVerifiedTeacherAt($school);

    $version = Version::factory()->create(['status' => EventStatus::Active]);
    $voicePart = VoicePart::factory()->create();
    $ensemble = Ensemble::factory()->create(['event_id' => $version->event_id]);
    $ensemble->voiceParts()->attach($voicePart->id);

    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationStatus::Invited,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    Livewire::actingAs($studentUser)
        ->test(School::class)
        ->call('selectSchool', $school->id)
        ->set('grade', 9)
        ->set("teacherSubjects.{$teacher->id}", ['chorus'])
        ->call('join');

    expect(Candidate::where('version_id', $version->id)->where('student_id', $studentUser->student->id)->exists())->toBeTrue();
});

test('a student cannot join a teacher not actually verified at the selected school', function () {
    $studentUser = makeSfdiStudent();
    $school = App\Models\School::factory()->create();
    SchoolGrade::factory()->create(['school_id' => $school->id, 'grade' => 9]);

    $otherTeacherUser = User::factory()->create();
    $unverifiedTeacher = Teacher::factory()->create(['user_id' => $otherTeacherUser->id]);
    // Not attached to $school at all.

    Livewire::actingAs($studentUser)
        ->test(School::class)
        ->call('selectSchool', $school->id)
        ->set('grade', 9)
        ->set("teacherSubjects.{$unverifiedTeacher->id}", ['chorus'])
        ->call('join')
        ->assertForbidden();
});

test('joining a new school deactivates the student\'s previous school', function () {
    $studentUser = makeSfdiStudent();
    $student = $studentUser->student;

    $oldSchool = App\Models\School::factory()->create();
    SchoolStudent::create(['student_id' => $student->id, 'school_id' => $oldSchool->id, 'is_active' => true, 'class_of' => 2029]);

    $newSchool = App\Models\School::factory()->create();
    SchoolGrade::factory()->create(['school_id' => $newSchool->id, 'grade' => 9]);
    $teacher = makeVerifiedTeacherAt($newSchool);

    Livewire::actingAs($studentUser)
        ->test(School::class)
        ->call('selectSchool', $newSchool->id)
        ->set('grade', 9)
        ->set("teacherSubjects.{$teacher->id}", ['chorus'])
        ->call('join');

    expect(SchoolStudent::where('student_id', $student->id)->where('school_id', $oldSchool->id)->value('is_active'))->toBeFalsy();
    expect(SchoolStudent::where('student_id', $student->id)->where('school_id', $newSchool->id)->value('is_active'))->toBeTruthy();
});

test('the Take a tour button does not auto-start once the tour has already been taken', function () {
    $studentUser = makeSfdiStudent();
    $studentUser->update(['dismissed_sfdi_school_orientation_at' => now()]);

    Livewire::actingAs($studentUser)
        ->test(School::class)
        ->assertSeeHtml('data-auto-start="0"');
});

test('dismissOrientation persists the dismissal for the acting user', function () {
    $studentUser = makeSfdiStudent();

    Livewire::actingAs($studentUser)
        ->test(School::class)
        ->call('dismissOrientation');

    expect($studentUser->fresh()->dismissed_sfdi_school_orientation_at)->not->toBeNull();
});
