<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\EnsembleGrade;
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
use App\Services\VersionRoleAssignmentService;
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

test('creating a VersionInvitation for a Sandbox Version DOES auto-enroll the invited teacher when they are the Event Manager', function () {
    [$teacher, $school] = makeAutoEnrollTeacherWithSchool();
    $version = makeAutoEnrollVersion(active: false);
    attachAutoEnrollVoicePart($version);

    app(VersionRoleAssignmentService::class)->bootstrapEventManager($teacher->user, $version);

    actingAs($teacher->user);
    $student = linkAutoEnrollStudent($teacher, $school);

    // bootstrapEventManager() already invited $teacher and ran its own
    // backfill; re-inviting here (as an Event Manager re-invite, per the
    // real-world Patricia Danner scenario) exercises the observer path
    // directly rather than only VersionRoleAssignmentService's backfill.
    VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->delete();
    inviteAutoEnrollTeacher($teacher, $version);

    expect(Candidate::where('version_id', $version->id)->where('student_id', $student->id)->exists())->toBeTrue();
});

test('creating a VersionInvitation for a Sandbox Version still does NOT auto-enroll a teacher who is not the Event Manager', function () {
    [$manager] = makeAutoEnrollTeacherWithSchool();
    [$teacher, $school] = makeAutoEnrollTeacherWithSchool();
    $version = makeAutoEnrollVersion(active: false);
    attachAutoEnrollVoicePart($version);

    app(VersionRoleAssignmentService::class)->bootstrapEventManager($manager->user, $version);

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

test('a Version transitioning from Sandbox into Active backfills enrollment for its already-invited teachers', function () {
    [$teacher, $school] = makeAutoEnrollTeacherWithSchool();
    $version = makeAutoEnrollVersion(active: false);
    attachAutoEnrollVoicePart($version);

    actingAs($teacher->user);
    $student = linkAutoEnrollStudent($teacher, $school);

    inviteAutoEnrollTeacher($teacher, $version);
    expect(Candidate::where('version_id', $version->id)->where('student_id', $student->id)->exists())->toBeFalse();

    $version->update(['status' => 'active']);

    expect(Candidate::where('version_id', $version->id)->where('student_id', $student->id)->exists())->toBeTrue();
});

test('a Version update that leaves status unchanged does not re-run enrollment backfill', function () {
    [$teacher, $school] = makeAutoEnrollTeacherWithSchool();
    $version = makeAutoEnrollVersion(active: true);
    attachAutoEnrollVoicePart($version);

    actingAs($teacher->user);
    $student = linkAutoEnrollStudent($teacher, $school);
    inviteAutoEnrollTeacher($teacher, $version);

    $candidate = Candidate::where('version_id', $version->id)->where('student_id', $student->id)->firstOrFail();
    $candidate->update(['status' => 'teacher_withdrawn']);

    $version->update(['name' => 'Renamed']);

    // A withdrawn candidate would be re-created by a bogus backfill run
    // (eligibleStudents() only excludes an existing Candidate row, and
    // withdrawal doesn't delete it — so a spurious re-run would instead
    // fail on the unique(version_id, student_id) constraint). Asserting
    // the status is still withdrawn confirms no backfill ran at all.
    expect($candidate->refresh()->status)->toBe(CandidateStatus::TeacherWithdrawn);
});

test('assigning a version-scoped role backfills enrollment for teachers already invited to a Sandbox Version', function () {
    [$teacher, $school] = makeAutoEnrollTeacherWithSchool();
    $version = makeAutoEnrollVersion(active: false);
    attachAutoEnrollVoicePart($version);

    actingAs($teacher->user);
    $student = linkAutoEnrollStudent($teacher, $school);
    inviteAutoEnrollTeacher($teacher, $version);

    expect(Candidate::where('version_id', $version->id)->where('student_id', $student->id)->exists())->toBeFalse();

    $founder = makeFounder();
    $newHire = User::factory()->create();
    app(VersionRoleAssignmentService::class)->assignRole($founder, $version, $newHire, 'Registration Manager');

    expect(Candidate::where('version_id', $version->id)->where('student_id', $student->id)->exists())->toBeTrue();
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

/**
 * Mirrors the NJ Elementary & Junior High All-State shape: an Elementary
 * Ensemble grade-restricted to 4-6 and a Junior High Ensemble restricted to
 * 7-9, each with its own exclusive VoicePart, on the same Event/Version.
 */
function attachGradeRestrictedVoicePart(Version $version, string $ensembleName, array $grades, string $voicePartName, int $sortOrder): VoicePart
{
    $voicePart = VoicePart::factory()->create(['name' => $voicePartName, 'sort_order' => $sortOrder]);
    $ensemble = Ensemble::factory()->create(['event_id' => $version->event_id, 'name' => $ensembleName]);
    $ensemble->voiceParts()->attach($voicePart->id);
    foreach ($grades as $grade) {
        EnsembleGrade::create(['ensemble_id' => $ensemble->id, 'grade' => $grade]);
    }

    return $voicePart;
}

function linkAutoEnrollStudentAtGrade(Teacher $teacher, School $school, int $grade): Student
{
    $student = Student::factory()->create();
    $student->schools()->attach($school->id, ['is_active' => true, 'class_of' => $school->senior_year + (12 - $grade)]);
    $student->teachers()->attach($teacher->id, [
        'school_id' => $school->id,
        'subject' => 'chorus',
        'role' => 'primary',
        'is_active' => true,
    ]);

    return $student;
}

test('auto-enrollment assigns the grade-appropriate voice part, not just the first sort-ordered one', function () {
    [$teacher, $school] = makeAutoEnrollTeacherWithSchool();
    $version = makeAutoEnrollVersion(active: true);
    // Soprano (Jr High, sorts first) would win a plain "first available"
    // fallback — Treble I (Elementary) must win instead for a 4th grader.
    attachGradeRestrictedVoicePart($version, 'Junior High Choir', [7, 8, 9], 'Soprano', 1);
    $trebleI = attachGradeRestrictedVoicePart($version, 'Elementary Choir', [4, 5, 6], 'Treble I', 2);
    inviteAutoEnrollTeacher($teacher, $version);

    actingAs($teacher->user);
    $student = linkAutoEnrollStudentAtGrade($teacher, $school, 4);

    $candidate = Candidate::where('version_id', $version->id)->where('student_id', $student->id)->first();

    expect($candidate)->not->toBeNull();
    expect($candidate->voice_part_id)->toBe($trebleI->id);
});

test('auto-enrollment is skipped when no Ensemble\'s grade config admits the student\'s grade', function () {
    [$teacher, $school] = makeAutoEnrollTeacherWithSchool();
    $version = makeAutoEnrollVersion(active: true);
    attachGradeRestrictedVoicePart($version, 'Elementary Choir', [4, 5, 6], 'Treble I', 1);
    inviteAutoEnrollTeacher($teacher, $version);

    actingAs($teacher->user);
    $student = linkAutoEnrollStudentAtGrade($teacher, $school, 9);

    expect(Candidate::where('version_id', $version->id)->where('student_id', $student->id)->exists())->toBeFalse();
});
