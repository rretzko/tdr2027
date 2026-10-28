<?php

declare(strict_types=1);

use App\Enums\TeacherRole;
use App\Models\Candidate;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\StudentTeacher;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Support\ClassOfCalculator;
use App\Support\ReplacedTeacherStudentTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

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

function inviteTeacherForTransfer(Teacher $teacher, Version $version): VersionInvitation
{
    return VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => 'invited',
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
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

test('transfer moves the candidate row for a transferred student to the new teacher', function () {
    $school = School::factory()->create();
    $replacedUser = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Former']);
    $replacedTeacher = Teacher::factory()->create(['user_id' => $replacedUser->id]);
    $newTeacher = Teacher::factory()->create();

    linkTeacherToSchoolForTransfer($replacedTeacher, $school);
    $newSchoolTeacher = linkTeacherToSchoolForTransfer($newTeacher, $school, $replacedUser->name);

    $current = enrollStudentForTransfer($school, $replacedTeacher, grade: 11);

    actingAs($replacedUser);

    $version = Version::factory()->create();
    inviteTeacherForTransfer($newTeacher, $version);

    $candidate = Candidate::factory()->create([
        'student_id' => $current->id,
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $replacedTeacher->id,
    ]);

    ReplacedTeacherStudentTransfer::transfer($newSchoolTeacher);

    expect($candidate->refresh()->teacher_id)->toBe($newTeacher->id);
});

test('transfer leaves a candidate with the replaced teacher when the new teacher has no invitation to that Version', function () {
    $school = School::factory()->create();
    $replacedUser = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Former']);
    $replacedTeacher = Teacher::factory()->create(['user_id' => $replacedUser->id]);
    $newTeacher = Teacher::factory()->create();

    linkTeacherToSchoolForTransfer($replacedTeacher, $school);
    $newSchoolTeacher = linkTeacherToSchoolForTransfer($newTeacher, $school, $replacedUser->name);

    $current = enrollStudentForTransfer($school, $replacedTeacher, grade: 11);

    actingAs($replacedUser);

    $candidate = Candidate::factory()->create([
        'student_id' => $current->id,
        'version_id' => Version::factory(),
        'school_id' => $school->id,
        'teacher_id' => $replacedTeacher->id,
    ]);

    expect(ReplacedTeacherStudentTransfer::transfer($newSchoolTeacher))->toBe(1);

    // The roster moves; the uninvited Version's candidate stays put.
    expect(StudentTeacher::where('student_id', $current->id)->value('teacher_id'))->toBe($newTeacher->id);
    expect($candidate->refresh()->teacher_id)->toBe($replacedTeacher->id);
});

test('transfer leaves a candidate with a teacher-paid allocation with the replaced teacher', function () {
    $school = School::factory()->create();
    $replacedUser = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Former']);
    $replacedTeacher = Teacher::factory()->create(['user_id' => $replacedUser->id]);
    $newTeacher = Teacher::factory()->create();

    linkTeacherToSchoolForTransfer($replacedTeacher, $school);
    $newSchoolTeacher = linkTeacherToSchoolForTransfer($newTeacher, $school, $replacedUser->name);

    $paidStudent = enrollStudentForTransfer($school, $replacedTeacher, grade: 11);
    $unpaidStudent = enrollStudentForTransfer($school, $replacedTeacher, grade: 10);

    actingAs($replacedUser);

    $version = Version::factory()->create();
    inviteTeacherForTransfer($newTeacher, $version);

    $paidCandidate = Candidate::factory()->create([
        'student_id' => $paidStudent->id, 'version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $replacedTeacher->id,
    ]);
    $unpaidCandidate = Candidate::factory()->create([
        'student_id' => $unpaidStudent->id, 'version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $replacedTeacher->id,
    ]);

    PaymentAllocation::factory()->create([
        'payment_transaction_id' => PaymentTransaction::factory()->create(['version_id' => $version->id, 'payer_teacher_id' => $replacedTeacher->id]),
        'candidate_id' => $paidCandidate->id,
    ]);

    ReplacedTeacherStudentTransfer::transfer($newSchoolTeacher);

    expect($paidCandidate->refresh()->teacher_id)->toBe($replacedTeacher->id);
    expect($unpaidCandidate->refresh()->teacher_id)->toBe($newTeacher->id);
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

test('transfer leaves a student no longer actively enrolled at the school with the replaced teacher', function () {
    $school = School::factory()->create();
    $replacedUser = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Former']);
    $replacedTeacher = Teacher::factory()->create(['user_id' => $replacedUser->id]);
    $newTeacher = Teacher::factory()->create();

    linkTeacherToSchoolForTransfer($replacedTeacher, $school);
    $newSchoolTeacher = linkTeacherToSchoolForTransfer($newTeacher, $school, $replacedUser->name);

    // e.g. a middle schooler who has moved up: class_of still looks current here.
    $movedOn = enrollStudentForTransfer($school, $replacedTeacher, grade: 9);
    $school->students()->updateExistingPivot($movedOn->id, ['is_active' => false]);

    expect(ReplacedTeacherStudentTransfer::transfer($newSchoolTeacher))->toBe(0);
    expect(StudentTeacher::where('student_id', $movedOn->id)->value('teacher_id'))->toBe($replacedTeacher->id);
});

test('transfer matches a typed name that omits the replaced teacher\'s honorific and differs in case and spacing', function () {
    $school = School::factory()->create();
    $replacedUser = User::factory()->create(['first_name' => 'Brian', 'last_name' => 'Williams']);
    $replacedUser->forceFill(['name' => 'Mr. Brian Williams'])->save();
    $replacedTeacher = Teacher::factory()->create(['user_id' => $replacedUser->id]);
    $newTeacher = Teacher::factory()->create();

    linkTeacherToSchoolForTransfer($replacedTeacher, $school);
    $newSchoolTeacher = linkTeacherToSchoolForTransfer($newTeacher, $school, '  brian   WILLIAMS ');

    $current = enrollStudentForTransfer($school, $replacedTeacher, grade: 11);

    expect(ReplacedTeacherStudentTransfer::transfer($newSchoolTeacher))->toBe(1);
    expect(StudentTeacher::where('student_id', $current->id)->value('teacher_id'))->toBe($newTeacher->id);
});

test('transfer prefers replaced_teacher_id over the typed name', function () {
    $school = School::factory()->create();
    $pickedUser = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Picked']);
    $pickedTeacher = Teacher::factory()->create(['user_id' => $pickedUser->id]);
    $namedUser = User::factory()->create(['first_name' => 'Sam', 'last_name' => 'Named']);
    $namedTeacher = Teacher::factory()->create(['user_id' => $namedUser->id]);
    $newTeacher = Teacher::factory()->create();

    linkTeacherToSchoolForTransfer($pickedTeacher, $school);
    linkTeacherToSchoolForTransfer($namedTeacher, $school);
    $newSchoolTeacher = linkTeacherToSchoolForTransfer($newTeacher, $school, $namedUser->name);
    $newSchoolTeacher->update(['replaced_teacher_id' => $pickedTeacher->id]);

    $pickedStudent = enrollStudentForTransfer($school, $pickedTeacher, grade: 11);
    $namedStudent = enrollStudentForTransfer($school, $namedTeacher, grade: 11);

    ReplacedTeacherStudentTransfer::transfer($newSchoolTeacher);

    expect(StudentTeacher::where('student_id', $pickedStudent->id)->value('teacher_id'))->toBe($newTeacher->id);
    expect(StudentTeacher::where('student_id', $namedStudent->id)->value('teacher_id'))->toBe($namedTeacher->id);
});

test('transfer is a no-op when the typed name matches more than one teacher at the school', function () {
    $school = School::factory()->create();
    $newTeacher = Teacher::factory()->create();

    foreach (range(1, 2) as $i) {
        $twin = Teacher::factory()->create(['user_id' => User::factory()->create(['first_name' => 'Chris', 'last_name' => 'Lee'])->id]);
        linkTeacherToSchoolForTransfer($twin, $school);
        enrollStudentForTransfer($school, $twin, grade: 11);
    }

    $newSchoolTeacher = linkTeacherToSchoolForTransfer($newTeacher, $school, 'Chris Lee');

    expect(ReplacedTeacherStudentTransfer::transfer($newSchoolTeacher))->toBe(0);
    expect(StudentTeacher::where('teacher_id', $newTeacher->id)->exists())->toBeFalse();
});

test('transfer stamps replacement_transferred_at', function () {
    $school = School::factory()->create();
    $replacedUser = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Former']);
    $replacedTeacher = Teacher::factory()->create(['user_id' => $replacedUser->id]);
    $newTeacher = Teacher::factory()->create();

    linkTeacherToSchoolForTransfer($replacedTeacher, $school);
    $newSchoolTeacher = linkTeacherToSchoolForTransfer($newTeacher, $school, $replacedUser->name);

    ReplacedTeacherStudentTransfer::transfer($newSchoolTeacher);

    expect($newSchoolTeacher->refresh()->replacement_transferred_at)->not->toBeNull();
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

test('normalizeName drops a leading honorific, case and extra spacing', function (string $raw) {
    expect(ReplacedTeacherStudentTransfer::normalizeName($raw))->toBe('brian williams');
})->with(['Mr. Brian Williams', 'mr brian williams', '  BRIAN   Williams ', 'Dr. Brian Williams', 'Mrs Brian Williams']);
