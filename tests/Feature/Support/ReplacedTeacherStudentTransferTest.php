<?php

declare(strict_types=1);

use App\Enums\TeacherRole;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\StudentTeacher;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\ClassOfCalculator;
use App\Support\ReplacedTeacherStudentTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function linkTeacherToSchoolForTransfer(Teacher $teacher, School $school, ?string $replacingTeacherName = null): SchoolTeacher
{
    return SchoolTeacher::create([
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'role' => TeacherRole::Primary->value,
        'is_active' => true,
        'replacing_teacher_name' => $replacingTeacherName,
    ]);
}

function enrollStudentForTransfer(School $school, Teacher $teacher, int $grade, string $subject = 'chorus'): Student
{
    $student = Student::factory()->create();
    $classOf = ClassOfCalculator::classOfFromGrade($grade, $school->senior_year);

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

test('transfer moves current students from the replaced teacher to the new teacher', function () {
    $school = School::factory()->create();
    $replacedUser = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Former']);
    $replacedTeacher = Teacher::factory()->create(['user_id' => $replacedUser->id]);
    $newTeacher = Teacher::factory()->create();

    linkTeacherToSchoolForTransfer($replacedTeacher, $school);
    $newSchoolTeacher = linkTeacherToSchoolForTransfer($newTeacher, $school, $replacedUser->name);

    $current = enrollStudentForTransfer($school, $replacedTeacher, grade: 11);

    $transferredCount = ReplacedTeacherStudentTransfer::transfer($newSchoolTeacher);

    expect($transferredCount)->toBe(1);

    $row = StudentTeacher::where('student_id', $current->id)->where('school_id', $school->id)->first();
    expect($row->teacher_id)->toBe($newTeacher->id);
});

test('transfer leaves already-graduated students with the replaced teacher', function () {
    $school = School::factory()->create();
    $replacedUser = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Former']);
    $replacedTeacher = Teacher::factory()->create(['user_id' => $replacedUser->id]);
    $newTeacher = Teacher::factory()->create();

    linkTeacherToSchoolForTransfer($replacedTeacher, $school);
    $newSchoolTeacher = linkTeacherToSchoolForTransfer($newTeacher, $school, $replacedUser->name);

    $student = Student::factory()->create();
    $alumniClassOf = $school->senior_year - 1;
    $school->students()->attach($student->id, ['is_active' => false, 'class_of' => $alumniClassOf]);

    StudentTeacher::create([
        'student_id' => $student->id,
        'teacher_id' => $replacedTeacher->id,
        'school_id' => $school->id,
        'subject' => 'chorus',
        'role' => TeacherRole::Primary->value,
        'is_active' => true,
    ]);

    $transferredCount = ReplacedTeacherStudentTransfer::transfer($newSchoolTeacher);

    expect($transferredCount)->toBe(0);

    $row = StudentTeacher::where('student_id', $student->id)->where('school_id', $school->id)->first();
    expect($row->teacher_id)->toBe($replacedTeacher->id);
});

test('transfer is a no-op when no replacing teacher was identified', function () {
    $school = School::factory()->create();
    $newTeacher = Teacher::factory()->create();
    $newSchoolTeacher = linkTeacherToSchoolForTransfer($newTeacher, $school);

    expect(ReplacedTeacherStudentTransfer::transfer($newSchoolTeacher))->toBe(0);
});

test('transfer is a no-op when the replacing teacher name does not match any teacher at the school', function () {
    $school = School::factory()->create();
    $newTeacher = Teacher::factory()->create();
    $newSchoolTeacher = linkTeacherToSchoolForTransfer($newTeacher, $school, 'Nobody Real');

    expect(ReplacedTeacherStudentTransfer::transfer($newSchoolTeacher))->toBe(0);
});

test('transfer drops the replaced teacher\'s row instead of violating the unique constraint when the new teacher already teaches that student the same subject', function () {
    $school = School::factory()->create();
    $replacedUser = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Former']);
    $replacedTeacher = Teacher::factory()->create(['user_id' => $replacedUser->id]);
    $newTeacher = Teacher::factory()->create();

    linkTeacherToSchoolForTransfer($replacedTeacher, $school);
    $newSchoolTeacher = linkTeacherToSchoolForTransfer($newTeacher, $school, $replacedUser->name);

    $coTaughtStudent = enrollStudentForTransfer($school, $replacedTeacher, grade: 10, subject: 'band');
    StudentTeacher::create([
        'student_id' => $coTaughtStudent->id,
        'teacher_id' => $newTeacher->id,
        'school_id' => $school->id,
        'subject' => 'band',
        'role' => TeacherRole::Coteacher->value,
        'is_active' => true,
    ]);

    $soleStudent = enrollStudentForTransfer($school, $replacedTeacher, grade: 9, subject: 'chorus');

    $transferredCount = ReplacedTeacherStudentTransfer::transfer($newSchoolTeacher);

    expect($transferredCount)->toBe(1);

    expect(StudentTeacher::where('student_id', $coTaughtStudent->id)->where('teacher_id', $replacedTeacher->id)->exists())->toBeFalse();
    expect(StudentTeacher::where('student_id', $coTaughtStudent->id)->where('teacher_id', $newTeacher->id)->exists())->toBeTrue();

    $soleRow = StudentTeacher::where('student_id', $soleStudent->id)->first();
    expect($soleRow->teacher_id)->toBe($newTeacher->id);
});
