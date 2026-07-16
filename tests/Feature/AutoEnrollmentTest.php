<?php

declare(strict_types=1);

use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\Pivots\SchoolStudent;
use App\Models\Pivots\StudentTeacher;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeAutoEnrollTeacherWithSchool(): array
{
    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);

    return [$teacher, $school];
}

function makeAutoEnrollVersion(bool $active = true): Version
{
    $event = Event::factory()->create();

    return $active
        ? Version::factory()->active()->create(['event_id' => $event->id])
        : Version::factory()->create(['event_id' => $event->id]);
}

/**
 * Attaches a fresh VoicePart to the Version's Event via a fresh Ensemble,
 * so it shows up in Version::availableVoiceParts(). $sortOrder is explicit
 * (not left to the factory's random default) since availableVoiceParts()
 * orders by sort_order, not creation order — tests relying on "the first
 * available voice part" need a guaranteed order.
 */
function attachAutoEnrollVoicePart(Version $version, string $name = 'Soprano', int $sortOrder = 1): VoicePart
{
    $voicePart = VoicePart::factory()->create(['name' => $name, 'sort_order' => $sortOrder]);
    $ensemble = Ensemble::factory()->create(['event_id' => $version->event_id]);
    $ensemble->voiceParts()->attach($voicePart->id);

    return $voicePart;
}

function linkAutoEnrollStudent(Teacher $teacher, School $school, ?int $voicePartId = null): Student
{
    $student = Student::factory()->create(['voice_part_id' => $voicePartId]);
    $student->schools()->attach($school->id, ['is_active' => true, 'class_of' => (int) date('Y') + 1]);
    $student->teachers()->attach($teacher->id, [
        'school_id' => $school->id,
        'subject' => 'chorus',
        'role' => 'primary',
        'is_active' => true,
    ]);

    return $student;
}

function inviteAutoEnrollTeacher(Teacher $teacher, Version $version): VersionInvitation
{
    return VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => 'invited',
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);
}

test('creating a VersionInvitation for an Active Version auto-enrolls the teacher\'s eligible students', function () {
    [$teacher, $school] = makeAutoEnrollTeacherWithSchool();
    $version = makeAutoEnrollVersion(active: true);
    attachAutoEnrollVoicePart($version);

    actingAs($teacher->user);
    $student = linkAutoEnrollStudent($teacher, $school);

    inviteAutoEnrollTeacher($teacher, $version);

    expect(Candidate::where('version_id', $version->id)->where('student_id', $student->id)->exists())->toBeTrue();
});

test('creating a VersionInvitation for a Sandbox Version does not auto-enroll anyone', function () {
    [$teacher, $school] = makeAutoEnrollTeacherWithSchool();
    $version = makeAutoEnrollVersion(active: false);
    attachAutoEnrollVoicePart($version);

    actingAs($teacher->user);
    $student = linkAutoEnrollStudent($teacher, $school);

    inviteAutoEnrollTeacher($teacher, $version);

    expect(Candidate::where('version_id', $version->id)->where('student_id', $student->id)->exists())->toBeFalse();
});

test('adding a new student to a teacher\'s roster auto-enrolls them into every Active Version the teacher is invited to', function () {
    [$teacher, $school] = makeAutoEnrollTeacherWithSchool();
    $version = makeAutoEnrollVersion(active: true);
    attachAutoEnrollVoicePart($version);
    inviteAutoEnrollTeacher($teacher, $version);

    actingAs($teacher->user);
    $student = linkAutoEnrollStudent($teacher, $school);

    expect(Candidate::where('version_id', $version->id)->where('student_id', $student->id)->exists())->toBeTrue();
});

test('adding a new student does not auto-enroll into a Version that is not Active', function () {
    [$teacher, $school] = makeAutoEnrollTeacherWithSchool();
    $version = makeAutoEnrollVersion(active: false);
    attachAutoEnrollVoicePart($version);
    inviteAutoEnrollTeacher($teacher, $version);

    actingAs($teacher->user);
    $student = linkAutoEnrollStudent($teacher, $school);

    expect(Candidate::where('version_id', $version->id)->where('student_id', $student->id)->exists())->toBeFalse();
});

test('reactivating an existing, previously-inactive student_teacher row also triggers auto-enrollment', function () {
    [$teacher, $school] = makeAutoEnrollTeacherWithSchool();
    $version = makeAutoEnrollVersion(active: true);
    attachAutoEnrollVoicePart($version);
    inviteAutoEnrollTeacher($teacher, $version);

    actingAs($teacher->user);
    $student = Student::factory()->create();
    $student->schools()->attach($school->id, ['is_active' => true, 'class_of' => (int) date('Y') + 1]);
    $student->teachers()->attach($teacher->id, [
        'school_id' => $school->id,
        'subject' => 'chorus',
        'role' => 'primary',
        'is_active' => false,
    ]);

    expect(Candidate::where('version_id', $version->id)->where('student_id', $student->id)->exists())->toBeFalse();

    $pivot = StudentTeacher::where('student_id', $student->id)->where('teacher_id', $teacher->id)->firstOrFail();
    $pivot->update(['is_active' => true]);

    expect(Candidate::where('version_id', $version->id)->where('student_id', $student->id)->exists())->toBeTrue();
});

test('reactivating a student\'s school (SchoolStudentObserver\'s cascade to student_teacher) also triggers auto-enrollment', function () {
    [$teacher, $school] = makeAutoEnrollTeacherWithSchool();
    $version = makeAutoEnrollVersion(active: true);
    attachAutoEnrollVoicePart($version);
    inviteAutoEnrollTeacher($teacher, $version);

    actingAs($teacher->user);
    $student = Student::factory()->create();

    // Both the school link and the teacher link start inactive — as if the
    // student's enrollment at this school has lapsed.
    $schoolStudent = SchoolStudent::factory()->create([
        'student_id' => $student->id,
        'school_id' => $school->id,
        'is_active' => false,
        'class_of' => (int) date('Y') + 1,
    ]);
    StudentTeacher::factory()->create([
        'student_id' => $student->id,
        'teacher_id' => $teacher->id,
        'school_id' => $school->id,
        'subject' => 'chorus',
        'role' => 'primary',
        'is_active' => false,
    ]);

    expect(Candidate::where('version_id', $version->id)->where('student_id', $student->id)->exists())->toBeFalse();

    // Reactivating the school link cascades to student_teacher.is_active
    // (SchoolStudentObserver::saved()) — this must fire the same
    // auto-enrollment as a direct roster-add, not silently no-op.
    $schoolStudent->update(['is_active' => true]);

    expect(Candidate::where('version_id', $version->id)->where('student_id', $student->id)->exists())->toBeTrue();
});

test('voice part resolution uses the student\'s own voice_part_id when it is one of the ensemble\'s voice parts', function () {
    [$teacher, $school] = makeAutoEnrollTeacherWithSchool();
    $version = makeAutoEnrollVersion(active: true);
    $soprano = attachAutoEnrollVoicePart($version, 'Soprano');
    attachAutoEnrollVoicePart($version, 'Alto');

    actingAs($teacher->user);
    $student = linkAutoEnrollStudent($teacher, $school, voicePartId: $soprano->id);

    inviteAutoEnrollTeacher($teacher, $version);

    $candidate = Candidate::where('version_id', $version->id)->where('student_id', $student->id)->first();
    expect($candidate->voice_part_id)->toBe($soprano->id);
});

test('voice part resolution falls back to the first available voice part when the student\'s voice_part_id is not one of the ensemble\'s', function () {
    [$teacher, $school] = makeAutoEnrollTeacherWithSchool();
    $version = makeAutoEnrollVersion(active: true);
    $soprano = attachAutoEnrollVoicePart($version, 'Soprano', sortOrder: 1);
    attachAutoEnrollVoicePart($version, 'Alto', sortOrder: 2);

    $unrelatedVoicePart = VoicePart::factory()->create(['name' => 'Not In This Ensemble']);

    actingAs($teacher->user);
    $student = linkAutoEnrollStudent($teacher, $school, voicePartId: $unrelatedVoicePart->id);

    inviteAutoEnrollTeacher($teacher, $version);

    $candidate = Candidate::where('version_id', $version->id)->where('student_id', $student->id)->first();
    expect($candidate->voice_part_id)->toBe($soprano->id);
});

test('voice part resolution falls back to the first available voice part when the student has no voice_part_id at all', function () {
    [$teacher, $school] = makeAutoEnrollTeacherWithSchool();
    $version = makeAutoEnrollVersion(active: true);
    $soprano = attachAutoEnrollVoicePart($version, 'Soprano');

    actingAs($teacher->user);
    $student = linkAutoEnrollStudent($teacher, $school, voicePartId: null);

    inviteAutoEnrollTeacher($teacher, $version);

    $candidate = Candidate::where('version_id', $version->id)->where('student_id', $student->id)->first();
    expect($candidate->voice_part_id)->toBe($soprano->id);
});

test('auto-enrollment is skipped when the Version\'s Event has no ensemble voice parts configured', function () {
    [$teacher, $school] = makeAutoEnrollTeacherWithSchool();
    $version = makeAutoEnrollVersion(active: true);
    // No Ensemble/EnsembleVoicePart attached — availableVoiceParts() is empty.

    actingAs($teacher->user);
    $student = linkAutoEnrollStudent($teacher, $school);

    inviteAutoEnrollTeacher($teacher, $version);

    expect(Candidate::where('version_id', $version->id)->where('student_id', $student->id)->exists())->toBeFalse();
});
