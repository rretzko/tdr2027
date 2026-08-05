<?php

declare(strict_types=1);

use App\Enums\TeacherRole;
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
use App\Services\TeacherStudentTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function ttsEnroll(School $school, Teacher $teacher, bool $current = true, string $subject = 'chorus'): Student
{
    $student = Student::factory()->create();
    $classOf = $current ? $school->senior_year + 1 : $school->senior_year - 1;

    $school->students()->attach($student->id, ['is_active' => true, 'class_of' => $classOf]);

    StudentTeacher::create([
        'student_id' => $student->id,
        'teacher_id' => $teacher->id,
        'school_id' => $school->id,
        'subject' => $subject,
        'role' => TeacherRole::Primary->value,
        'is_active' => true,
    ]);

    return $student;
}

test('currentStudents only returns students still enrolled (class_of at or after the school\'s senior year)', function () {
    $school = School::factory()->create();
    $teacher = Teacher::factory()->create();

    $current = ttsEnroll($school, $teacher, current: true);
    $graduated = ttsEnroll($school, $teacher, current: false);

    $service = app(TeacherStudentTransferService::class);
    $students = $service->currentStudents($school, $teacher);

    expect($students->pluck('id')->all())->toBe([$current->id])
        ->and($students->pluck('id')->all())->not->toContain($graduated->id);
});

test('transfer moves the student_teacher row and the Candidate row within the same school', function () {
    $school = School::factory()->create();
    $fromTeacher = Teacher::factory()->create();
    $toTeacher = Teacher::factory()->create();
    $student = ttsEnroll($school, $fromTeacher);

    $version = Version::factory()->create();
    actingAs(User::factory()->create());
    $candidate = Candidate::factory()->create([
        'student_id' => $student->id,
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $fromTeacher->id,
    ]);

    $service = app(TeacherStudentTransferService::class);
    $count = $service->transfer($school, $fromTeacher, $school, $toTeacher, [$student->id]);

    expect($count)->toBe(1);

    $row = StudentTeacher::where('student_id', $student->id)->where('school_id', $school->id)->first();
    expect($row->teacher_id)->toBe($toTeacher->id)
        ->and($row->is_active)->toBeTrue();

    expect($candidate->refresh()->teacher_id)->toBe($toTeacher->id)
        ->and($candidate->school_id)->toBe($school->id);
});

test('transfer across schools deactivates the old school_student row and activates a new one, preserving class_of', function () {
    $fromSchool = School::factory()->create();
    $toSchool = School::factory()->create();
    $fromTeacher = Teacher::factory()->create();
    $toTeacher = Teacher::factory()->create();
    $student = ttsEnroll($fromSchool, $fromTeacher);

    $originalClassOf = SchoolStudent::where('student_id', $student->id)->where('school_id', $fromSchool->id)->value('class_of');

    $service = app(TeacherStudentTransferService::class);
    $service->transfer($fromSchool, $fromTeacher, $toSchool, $toTeacher, [$student->id]);

    expect(SchoolStudent::where('student_id', $student->id)->where('school_id', $fromSchool->id)->value('is_active'))->toBeFalse();

    $newRow = SchoolStudent::where('student_id', $student->id)->where('school_id', $toSchool->id)->first();
    expect($newRow->is_active)->toBeTrue()
        ->and((int) $newRow->class_of)->toBe((int) $originalClassOf);

    $movedTeacherRow = StudentTeacher::where('student_id', $student->id)->where('teacher_id', $toTeacher->id)->first();
    expect($movedTeacherRow->school_id)->toBe($toSchool->id);
});

test('transfer drops the old student_teacher row when the destination already has a matching subject row', function () {
    $school = School::factory()->create();
    $fromTeacher = Teacher::factory()->create();
    $toTeacher = Teacher::factory()->create();
    $student = ttsEnroll($school, $fromTeacher, subject: 'chorus');

    StudentTeacher::create([
        'student_id' => $student->id,
        'teacher_id' => $toTeacher->id,
        'school_id' => $school->id,
        'subject' => 'chorus',
        'role' => TeacherRole::Coteacher->value,
        'is_active' => true,
    ]);

    $service = app(TeacherStudentTransferService::class);
    $count = $service->transfer($school, $fromTeacher, $school, $toTeacher, [$student->id]);

    expect($count)->toBe(1);
    expect(StudentTeacher::where('student_id', $student->id)->where('teacher_id', $fromTeacher->id)->exists())->toBeFalse();
    expect(StudentTeacher::where('student_id', $student->id)->where('teacher_id', $toTeacher->id)->count())->toBe(1);
});

test('transfer only moves students who are actually current for the from teacher/school, ignoring anything else submitted', function () {
    $school = School::factory()->create();
    $fromTeacher = Teacher::factory()->create();
    $toTeacher = Teacher::factory()->create();
    $realStudent = ttsEnroll($school, $fromTeacher);
    $unrelatedStudent = Student::factory()->create();

    $service = app(TeacherStudentTransferService::class);
    $count = $service->transfer($school, $fromTeacher, $school, $toTeacher, [$realStudent->id, $unrelatedStudent->id, 999999]);

    expect($count)->toBe(1);
    expect(StudentTeacher::where('student_id', $realStudent->id)->where('teacher_id', $toTeacher->id)->exists())->toBeTrue();
});

test('transfer triggers auto-enrollment into another open Active Version the destination teacher is invited to', function () {
    $school = School::factory()->create();
    $fromTeacher = Teacher::factory()->create();
    $toTeacher = Teacher::factory()->create();
    $toTeacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);

    $student = ttsEnroll($school, $fromTeacher);

    $event = Event::factory()->create();
    $otherVersion = Version::factory()->active()->create(['event_id' => $event->id]);

    $voicePart = VoicePart::factory()->create(['sort_order' => 1]);
    $ensemble = Ensemble::factory()->create(['event_id' => $event->id]);
    $ensemble->voiceParts()->attach($voicePart->id);

    VersionInvitation::create([
        'version_id' => $otherVersion->id,
        'teacher_id' => $toTeacher->id,
        'status' => 'invited',
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    actingAs(User::factory()->create());

    $service = app(TeacherStudentTransferService::class);
    $service->transfer($school, $fromTeacher, $school, $toTeacher, [$student->id]);

    expect(Candidate::where('version_id', $otherVersion->id)->where('student_id', $student->id)->where('teacher_id', $toTeacher->id)->exists())->toBeTrue();
});
