<?php

declare(strict_types=1);

use App\Enums\TeacherRole;
use App\Livewire\Events\WebRegistration;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Pivots\StudentTeacher;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeWebRegVersion(): Version
{
    $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    return Version::factory()->create(['event_id' => $event->id]);
}

function inviteWebRegTeacher(Version $version, Teacher $teacher): VersionInvitation
{
    return VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => 'invited',
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);
}

test('mount aborts with 403 for a user with no version-scoped role on the Version', function () {
    $user = User::factory()->create();
    $version = makeWebRegVersion();

    Livewire::actingAs($user)
        ->test(WebRegistration::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount aborts with 403 for a Registration Manager (source doc names only Web Registration Manager)', function () {
    $user = User::factory()->create();
    $version = makeWebRegVersion();
    grantVersionRole($user, $version, 'Registration Manager');

    Livewire::actingAs($user)
        ->test(WebRegistration::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount allows Founder, Event Manager, and Web Registration Manager', function () {
    $version = makeWebRegVersion();

    Livewire::actingAs(makeFounder())
        ->test(WebRegistration::class, ['version' => $version])
        ->assertOk();

    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $version, 'Event Manager');
    Livewire::actingAs($eventManager)
        ->test(WebRegistration::class, ['version' => $version])
        ->assertOk();

    $webRegManager = User::factory()->create();
    grantVersionRole($webRegManager, $version, 'Web Registration Manager');
    Livewire::actingAs($webRegManager)
        ->test(WebRegistration::class, ['version' => $version])
        ->assertOk();
});

test('selecting a From school with exactly one invited teacher auto-selects that teacher and checks all their current students', function () {
    $version = makeWebRegVersion();
    $manager = User::factory()->create();
    grantVersionRole($manager, $version, 'Web Registration Manager');

    $school = School::factory()->create();
    $teacher = Teacher::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $teacher);

    $studentA = Student::factory()->create();
    $studentB = Student::factory()->create();
    foreach ([$studentA, $studentB] as $student) {
        $school->students()->attach($student->id, ['is_active' => true, 'class_of' => $school->senior_year + 1]);
        StudentTeacher::create([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'school_id' => $school->id,
            'subject' => 'chorus',
            'role' => TeacherRole::Primary->value,
            'is_active' => true,
        ]);
    }

    Livewire::actingAs($manager)
        ->test(WebRegistration::class, ['version' => $version])
        ->set('fromSchoolId', $school->id)
        ->assertSet('fromTeacherId', $teacher->id)
        ->assertSet('selectedStudentIds', fn (array $ids): bool => collect($ids)->sort()->values()->all() === collect([$studentA->id, $studentB->id])->sort()->values()->all());
});

test('selecting a From school with more than one invited teacher does not auto-select a teacher', function () {
    $version = makeWebRegVersion();
    $manager = User::factory()->create();
    grantVersionRole($manager, $version, 'Web Registration Manager');

    $school = School::factory()->create();
    $teacherOne = Teacher::factory()->create();
    $teacherOne->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $teacherOne);
    $teacherTwo = Teacher::factory()->create();
    $teacherTwo->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $teacherTwo);

    Livewire::actingAs($manager)
        ->test(WebRegistration::class, ['version' => $version])
        ->set('fromSchoolId', $school->id)
        ->assertSet('fromTeacherId', null)
        ->assertSet('selectedStudentIds', []);
});

test('selecting a To school with exactly one invited teacher auto-selects that teacher', function () {
    $version = makeWebRegVersion();
    $manager = User::factory()->create();
    grantVersionRole($manager, $version, 'Web Registration Manager');

    $school = School::factory()->create();
    $teacher = Teacher::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $teacher);

    Livewire::actingAs($manager)
        ->test(WebRegistration::class, ['version' => $version])
        ->set('toSchoolId', $school->id)
        ->assertSet('toTeacherId', $teacher->id);
});

test('selecting a To school with more than one invited teacher does not auto-select a teacher', function () {
    $version = makeWebRegVersion();
    $manager = User::factory()->create();
    grantVersionRole($manager, $version, 'Web Registration Manager');

    $school = School::factory()->create();
    $teacherOne = Teacher::factory()->create();
    $teacherOne->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $teacherOne);
    $teacherTwo = Teacher::factory()->create();
    $teacherTwo->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $teacherTwo);

    Livewire::actingAs($manager)
        ->test(WebRegistration::class, ['version' => $version])
        ->set('toSchoolId', $school->id)
        ->assertSet('toTeacherId', null);
});

test('unchecking all From students resets the To selection back to its disabled/empty state', function () {
    $version = makeWebRegVersion();
    $manager = User::factory()->create();
    grantVersionRole($manager, $version, 'Web Registration Manager');

    $fromSchool = School::factory()->create();
    $fromTeacher = Teacher::factory()->create();
    $fromTeacher->schools()->attach($fromSchool->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $fromTeacher);

    $toSchool = School::factory()->create();
    $toTeacher = Teacher::factory()->create();
    $toTeacher->schools()->attach($toSchool->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $toTeacher);

    $student = Student::factory()->create();
    $fromSchool->students()->attach($student->id, ['is_active' => true, 'class_of' => $fromSchool->senior_year + 1]);
    StudentTeacher::create([
        'student_id' => $student->id,
        'teacher_id' => $fromTeacher->id,
        'school_id' => $fromSchool->id,
        'subject' => 'chorus',
        'role' => TeacherRole::Primary->value,
        'is_active' => true,
    ]);

    Livewire::actingAs($manager)
        ->test(WebRegistration::class, ['version' => $version])
        ->set('fromSchoolId', $fromSchool->id)
        ->set('fromTeacherId', $fromTeacher->id) // auto-selected anyway (only teacher), set explicitly for clarity
        ->assertSet('selectedStudentIds', [$student->id])
        ->set('toSchoolId', $toSchool->id)
        ->assertSet('toTeacherId', $toTeacher->id) // auto-selected (only teacher at toSchool)
        ->set('selectedStudentIds', [])
        ->assertSet('toSchoolId', null)
        ->assertSet('toTeacherId', null);
});

test('the To teacher list excludes the From teacher when the same school is picked on both sides', function () {
    $version = makeWebRegVersion();
    $manager = User::factory()->create();
    grantVersionRole($manager, $version, 'Web Registration Manager');

    $school = School::factory()->create();
    $fromTeacher = Teacher::factory()->create();
    $fromTeacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $fromTeacher);
    $otherTeacher = Teacher::factory()->create();
    $otherTeacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $otherTeacher);

    $student = Student::factory()->create();
    $school->students()->attach($student->id, ['is_active' => true, 'class_of' => $school->senior_year + 1]);
    StudentTeacher::create([
        'student_id' => $student->id,
        'teacher_id' => $fromTeacher->id,
        'school_id' => $school->id,
        'subject' => 'chorus',
        'role' => TeacherRole::Primary->value,
        'is_active' => true,
    ]);

    Livewire::actingAs($manager)
        ->test(WebRegistration::class, ['version' => $version])
        ->set('fromSchoolId', $school->id)
        ->set('fromTeacherId', $fromTeacher->id)
        ->set('toSchoolId', $school->id)
        // Only one selectable teacher remains (otherTeacher) once fromTeacher
        // is excluded, so it should auto-select rather than staying null.
        ->assertSet('toTeacherId', $otherTeacher->id);
});

test('a previously-picked To teacher is cleared if a From change makes it collide', function () {
    $version = makeWebRegVersion();
    $manager = User::factory()->create();
    grantVersionRole($manager, $version, 'Web Registration Manager');

    $school = School::factory()->create();
    $teacherA = Teacher::factory()->create();
    $teacherA->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $teacherA);
    $teacherB = Teacher::factory()->create();
    $teacherB->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $teacherB);

    $student = Student::factory()->create();
    $school->students()->attach($student->id, ['is_active' => true, 'class_of' => $school->senior_year + 1]);
    StudentTeacher::create([
        'student_id' => $student->id,
        'teacher_id' => $teacherA->id,
        'school_id' => $school->id,
        'subject' => 'chorus',
        'role' => TeacherRole::Primary->value,
        'is_active' => true,
    ]);
    StudentTeacher::create([
        'student_id' => Student::factory()->create()->id,
        'teacher_id' => $teacherB->id,
        'school_id' => $school->id,
        'subject' => 'chorus',
        'role' => TeacherRole::Primary->value,
        'is_active' => true,
    ]);

    $component = Livewire::actingAs($manager)
        ->test(WebRegistration::class, ['version' => $version])
        ->set('fromSchoolId', $school->id)
        ->set('fromTeacherId', $teacherB->id)
        ->set('toSchoolId', $school->id)
        ->set('toTeacherId', $teacherA->id)
        ->assertSet('toTeacherId', $teacherA->id);

    // Switching From to teacherA (the currently-picked To teacher) collides — clear it.
    $component->set('fromTeacherId', $teacherA->id)
        ->assertSet('toTeacherId', null);
});

test('transferStudents rejects the same teacher on both sides even if submitted directly', function () {
    $version = makeWebRegVersion();
    $manager = User::factory()->create();
    grantVersionRole($manager, $version, 'Web Registration Manager');

    $school = School::factory()->create();
    $teacher = Teacher::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $teacher);

    $student = Student::factory()->create();
    $school->students()->attach($student->id, ['is_active' => true, 'class_of' => $school->senior_year + 1]);
    StudentTeacher::create([
        'student_id' => $student->id,
        'teacher_id' => $teacher->id,
        'school_id' => $school->id,
        'subject' => 'chorus',
        'role' => TeacherRole::Primary->value,
        'is_active' => true,
    ]);

    Livewire::actingAs($manager)
        ->test(WebRegistration::class, ['version' => $version])
        ->set('fromSchoolId', $school->id)
        ->set('fromTeacherId', $teacher->id)
        ->set('toSchoolId', $school->id)
        ->set('toTeacherId', $teacher->id)
        ->set('selectedStudentIds', [$student->id])
        ->call('transferStudents')
        ->assertHasErrors(['toTeacherId']);
});

test('the "Transfer To" prompt shows only until From is fully selected', function () {
    $version = makeWebRegVersion();
    $manager = User::factory()->create();
    grantVersionRole($manager, $version, 'Web Registration Manager');

    $school = School::factory()->create();
    $teacher = Teacher::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $teacher);

    $student = Student::factory()->create();
    $school->students()->attach($student->id, ['is_active' => true, 'class_of' => $school->senior_year + 1]);
    StudentTeacher::create([
        'student_id' => $student->id,
        'teacher_id' => $teacher->id,
        'school_id' => $school->id,
        'subject' => 'chorus',
        'role' => TeacherRole::Primary->value,
        'is_active' => true,
    ]);

    Livewire::actingAs($manager)
        ->test(WebRegistration::class, ['version' => $version])
        ->assertSee('Select a school, teacher, and at least one student on the left first.')
        ->set('fromSchoolId', $school->id)
        ->set('fromTeacherId', $teacher->id)
        ->assertDontSee('Select a school, teacher, and at least one student on the left first.');
});

test('manually selecting a From teacher checks all of their current students', function () {
    $version = makeWebRegVersion();
    $manager = User::factory()->create();
    grantVersionRole($manager, $version, 'Web Registration Manager');

    $school = School::factory()->create();
    $teacherOne = Teacher::factory()->create();
    $teacherOne->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $teacherOne);
    $teacherTwo = Teacher::factory()->create();
    $teacherTwo->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $teacherTwo);

    $student = Student::factory()->create();
    $school->students()->attach($student->id, ['is_active' => true, 'class_of' => $school->senior_year + 1]);
    StudentTeacher::create([
        'student_id' => $student->id,
        'teacher_id' => $teacherTwo->id,
        'school_id' => $school->id,
        'subject' => 'chorus',
        'role' => TeacherRole::Primary->value,
        'is_active' => true,
    ]);

    Livewire::actingAs($manager)
        ->test(WebRegistration::class, ['version' => $version])
        ->set('fromSchoolId', $school->id)
        ->set('fromTeacherId', $teacherTwo->id)
        ->assertSet('selectedStudentIds', [$student->id]);
});

test('switching the From teacher back and forth always reflects that teacher\'s real current roster (no stale query state)', function () {
    $version = makeWebRegVersion();
    $manager = User::factory()->create();
    grantVersionRole($manager, $version, 'Web Registration Manager');

    $school = School::factory()->create();

    $teacherA = Teacher::factory()->create();
    $teacherA->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $teacherA);
    $teacherAStudents = collect(range(1, 5))->map(function () use ($school, $teacherA) {
        $student = Student::factory()->create();
        $school->students()->attach($student->id, ['is_active' => true, 'class_of' => $school->senior_year + 1]);
        StudentTeacher::create([
            'student_id' => $student->id,
            'teacher_id' => $teacherA->id,
            'school_id' => $school->id,
            'subject' => 'chorus',
            'role' => TeacherRole::Primary->value,
            'is_active' => true,
        ]);

        return $student->id;
    })->sort()->values()->all();

    $teacherB = Teacher::factory()->create();
    $teacherB->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $teacherB);

    $component = Livewire::actingAs($manager)
        ->test(WebRegistration::class, ['version' => $version])
        ->set('fromSchoolId', $school->id)
        ->assertSet('fromTeacherId', null)
        ->set('fromTeacherId', $teacherA->id)
        ->assertSet('selectedStudentIds', fn (array $ids): bool => collect($ids)->sort()->values()->all() === $teacherAStudents)
        ->set('fromTeacherId', $teacherB->id)
        ->assertSet('selectedStudentIds', []);

    $component->set('fromTeacherId', $teacherA->id)
        ->assertSet('selectedStudentIds', fn (array $ids): bool => collect($ids)->sort()->values()->all() === $teacherAStudents);
});

test('teachersForImpersonation only returns invited teachers matching the search term', function () {
    $version = makeWebRegVersion();
    $manager = User::factory()->create();
    grantVersionRole($manager, $version, 'Web Registration Manager');

    $invitedUser = User::factory()->create(['first_name' => 'Jamie', 'last_name' => 'Lannister']);
    $invitedTeacher = Teacher::factory()->create(['user_id' => $invitedUser->id]);
    inviteWebRegTeacher($version, $invitedTeacher);

    $uninvitedUser = User::factory()->create(['first_name' => 'Arya', 'last_name' => 'Stark']);
    Teacher::factory()->create(['user_id' => $uninvitedUser->id]);

    Livewire::actingAs($manager)
        ->test(WebRegistration::class, ['version' => $version])
        ->set('impersonateSearch', 'Lannister')
        ->assertSee('Jamie')
        ->set('impersonateSearch', 'Stark')
        ->assertDontSee('Arya');
});

test('impersonate logs the manager in as an invited teacher, scoped to this Version', function () {
    $version = makeWebRegVersion();
    $manager = User::factory()->create();
    grantVersionRole($manager, $version, 'Web Registration Manager');

    $teacherUser = User::factory()->create();
    $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id, 'onboarding_completed_at' => now()]);
    inviteWebRegTeacher($version, $teacher);

    Livewire::actingAs($manager)
        ->test(WebRegistration::class, ['version' => $version])
        ->call('impersonate', $teacherUser->id)
        ->assertRedirect(route('registrations.version', $version));

    expect(auth()->id())->toBe($teacherUser->id);
    expect(session('impersonator_id'))->toBe($manager->id);
    expect(session('impersonation_scope'))->toBe('web_registration_manager');
    expect(session('impersonation_version_id'))->toBe($version->id);
});

test('impersonate rejects a teacher not invited to this Version', function () {
    $version = makeWebRegVersion();
    $manager = User::factory()->create();
    grantVersionRole($manager, $version, 'Web Registration Manager');

    $teacherUser = User::factory()->create();
    Teacher::factory()->create(['user_id' => $teacherUser->id]);

    Livewire::actingAs($manager)
        ->test(WebRegistration::class, ['version' => $version])
        ->call('impersonate', $teacherUser->id)
        ->assertStatus(403);
});

test('transferStudents moves an invited-teacher-owned student to another invited teacher', function () {
    $version = makeWebRegVersion();
    $manager = User::factory()->create();
    grantVersionRole($manager, $version, 'Web Registration Manager');

    $school = School::factory()->create();

    $fromTeacher = Teacher::factory()->create();
    $fromTeacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $fromTeacher);

    $toTeacher = Teacher::factory()->create();
    $toTeacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $toTeacher);

    $student = Student::factory()->create();
    $school->students()->attach($student->id, ['is_active' => true, 'class_of' => $school->senior_year + 1]);
    StudentTeacher::create([
        'student_id' => $student->id,
        'teacher_id' => $fromTeacher->id,
        'school_id' => $school->id,
        'subject' => 'chorus',
        'role' => TeacherRole::Primary->value,
        'is_active' => true,
    ]);

    Livewire::actingAs($manager)
        ->test(WebRegistration::class, ['version' => $version])
        ->set('fromSchoolId', $school->id)
        ->set('fromTeacherId', $fromTeacher->id)
        ->set('toSchoolId', $school->id)
        ->set('toTeacherId', $toTeacher->id)
        ->set('selectedStudentIds', [$student->id])
        ->call('transferStudents')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    expect(StudentTeacher::where('student_id', $student->id)->where('teacher_id', $toTeacher->id)->exists())->toBeTrue();
});

test('transferStudents rejects a toTeacher who is not invited to this Version', function () {
    $version = makeWebRegVersion();
    $manager = User::factory()->create();
    grantVersionRole($manager, $version, 'Web Registration Manager');

    $school = School::factory()->create();

    $fromTeacher = Teacher::factory()->create();
    $fromTeacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    inviteWebRegTeacher($version, $fromTeacher);

    $toTeacher = Teacher::factory()->create();

    $student = Student::factory()->create();
    $school->students()->attach($student->id, ['is_active' => true, 'class_of' => $school->senior_year + 1]);
    StudentTeacher::create([
        'student_id' => $student->id,
        'teacher_id' => $fromTeacher->id,
        'school_id' => $school->id,
        'subject' => 'chorus',
        'role' => TeacherRole::Primary->value,
        'is_active' => true,
    ]);

    Livewire::actingAs($manager)
        ->test(WebRegistration::class, ['version' => $version])
        ->set('fromSchoolId', $school->id)
        ->set('fromTeacherId', $fromTeacher->id)
        ->set('toSchoolId', $school->id)
        ->set('toTeacherId', $toTeacher->id)
        ->set('selectedStudentIds', [$student->id])
        ->call('transferStudents')
        ->assertStatus(403);
});
