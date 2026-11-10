<?php

declare(strict_types=1);

use App\Models\Pivots\StudentTeacher;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentSignupDismissal;
use App\Models\Teacher;
use App\Models\User;
use App\Services\UnjoinedSignupFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function unjoinedFinderTeacher(School $school, string $schoolEmail = 'director@district-x.org', bool $verified = true): Teacher
{
    $teacher = Teacher::factory()->create();
    $teacher->schools()->attach($school->id, [
        'role' => 'primary',
        'is_active' => true,
        'school_email' => $schoolEmail,
        'verified_at' => $verified ? now() : null,
    ]);

    return $teacher;
}

function unjoinedFinderSignup(string $email, array $userAttributes = []): Student
{
    $user = User::factory()->create($userAttributes + ['email' => $email, 'email_unverifiable' => false, 'email_verified_at' => null]);

    return Student::factory()->create(['user_id' => $user->id]);
}

/** @return list<int> */
function unjoinedFinderIds(Teacher $teacher): array
{
    return app(UnjoinedSignupFinder::class)->forTeacher($teacher)->map(fn (array $match) => $match['student']->id)->all();
}

test('a sign-up with the teacher school email domain is listed with that school', function () {
    $school = School::factory()->create();
    $teacher = unjoinedFinderTeacher($school);
    $signup = unjoinedFinderSignup('kid@district-x.org');

    $matches = app(UnjoinedSignupFinder::class)->forTeacher($teacher);

    expect($matches)->toHaveCount(1)
        ->and($matches->first()['student']->id)->toBe($signup->id)
        ->and($matches->first()['school']->id)->toBe($school->id)
        ->and($matches->first()['domain'])->toBe('@district-x.org');
});

test('a subdomain of the teacher domain matches', function () {
    $teacher = unjoinedFinderTeacher(School::factory()->create());
    $signup = unjoinedFinderSignup('kid@Students.District-X.org');

    expect(unjoinedFinderIds($teacher))->toBe([$signup->id]);
});

test('a domain shared by at least three enrolled students at the school matches, one used by fewer does not', function () {
    $school = School::factory()->create();
    $teacher = unjoinedFinderTeacher($school);

    foreach (['a@msdstudent.org', 'b@msdstudent.org', 'c@msdstudent.org', 'd@familyisp.net', 'e@familyisp.net'] as $email) {
        $school->students()->attach(unjoinedFinderSignup($email)->id, ['is_active' => true, 'class_of' => 2030]);
    }

    $districtSignup = unjoinedFinderSignup('new@msdstudent.org');
    unjoinedFinderSignup('stranger@familyisp.net');

    expect(unjoinedFinderIds($teacher))->toBe([$districtSignup->id]);
});

test('a sign-up flagged email_unverifiable (school address that skips verification) is still listed', function () {
    $teacher = unjoinedFinderTeacher(School::factory()->create());
    $signup = unjoinedFinderSignup('kid@district-x.org', ['email_unverifiable' => true]);

    expect(unjoinedFinderIds($teacher))->toBe([$signup->id]);
});

test('the studentfolder.info placeholder domain is never treated as a school domain', function () {
    $school = School::factory()->create();
    $teacher = unjoinedFinderTeacher($school);

    foreach (range(1, 3) as $i) {
        $school->students()->attach(unjoinedFinderSignup("placeholder{$i}@studentfolder.info", ['email_unverifiable' => true])->id, ['is_active' => true, 'class_of' => 2030]);
    }

    unjoinedFinderSignup('other-school-kid@studentfolder.info', ['email_unverifiable' => true]);

    expect(unjoinedFinderIds($teacher))->toBe([]);
});

test('free webmail never matches, even as the teacher school email', function () {
    $teacher = unjoinedFinderTeacher(School::factory()->create(), 'director@gmail.com');
    unjoinedFinderSignup('kid@gmail.com');

    expect(unjoinedFinderIds($teacher))->toBe([]);
});

test('an unverified school link shows nothing', function () {
    $teacher = unjoinedFinderTeacher(School::factory()->create(), verified: false);
    unjoinedFinderSignup('kid@district-x.org');

    expect(unjoinedFinderIds($teacher))->toBe([]);
});

test('students who joined a school, are on a roster, were teacher-created, are old, or are teachers are excluded', function () {
    $school = School::factory()->create();
    $teacher = unjoinedFinderTeacher($school);

    $joined = unjoinedFinderSignup('joined@district-x.org');
    School::factory()->create()->students()->attach($joined->id, ['is_active' => true, 'class_of' => 2030]);

    $rostered = unjoinedFinderSignup('rostered@district-x.org');
    StudentTeacher::create(['student_id' => $rostered->id, 'teacher_id' => Teacher::factory()->create()->id, 'school_id' => $school->id, 'subject' => 'band', 'role' => 'primary', 'is_active' => false]);

    unjoinedFinderSignup('0f8c-uuid@studentfolder.info', ['email_unverifiable' => true]);
    unjoinedFinderSignup('old@district-x.org', ['created_at' => now()->subDays(200)]);

    $teacherUser = unjoinedFinderSignup('teacher@district-x.org');
    Teacher::factory()->create(['user_id' => $teacherUser->user_id]);

    expect(unjoinedFinderIds($teacher))->toBe([]);
});

test('a sign-up the teacher dismissed is hidden for that teacher only', function () {
    $school = School::factory()->create();
    $teacher = unjoinedFinderTeacher($school);
    $colleague = unjoinedFinderTeacher($school);
    $signup = unjoinedFinderSignup('kid@district-x.org');

    StudentSignupDismissal::create(['teacher_id' => $teacher->id, 'student_id' => $signup->id]);

    expect(unjoinedFinderIds($teacher))->toBe([])
        ->and(unjoinedFinderIds($colleague))->toBe([$signup->id]);
});
