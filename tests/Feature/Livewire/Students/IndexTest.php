<?php

declare(strict_types=1);

use App\Livewire\Students\Index;
use App\Models\EmergencyContact;
use App\Models\HomeAddress;
use App\Models\Instrument;
use App\Models\Pivots\StudentTeacher;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\ClassOfCalculator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeStudentsIndexTeacherUser(): User
{
    $user = User::factory()->create();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    return $user;
}

/**
 * Claims a student for a teacher at a school: enrolls them at the school with the
 * given grade, then links them to the teacher for the given subject.
 */
function claimStudentForTeacher(Teacher $teacher, School $school, string $firstName, string $lastName, int $grade = 9, string $subject = 'band'): StudentTeacher
{
    $student = Student::factory()->create();
    $student->user->update(['first_name' => $firstName, 'last_name' => $lastName]);

    $classOf = ClassOfCalculator::classOfFromGrade($grade, $school->senior_year);
    $school->students()->attach($student->id, ['is_active' => true, 'class_of' => $classOf]);

    return StudentTeacher::create([
        'student_id' => $student->id,
        'teacher_id' => $teacher->id,
        'school_id' => $school->id,
        'subject' => $subject,
        'role' => 'primary',
        'is_active' => true,
    ]);
}

/**
 * @return array{id: null, name: string, relationship: string, email: string, cell_phone: string, home_phone: string, work_phone: string}
 */
function validEmergencyContact(): array
{
    return [
        'id' => null,
        'name' => 'Emergency Contact',
        'relationship' => 'mother',
        'email' => 'contact@example.com',
        'cell_phone' => '5551234567',
        'home_phone' => '',
        'work_phone' => '',
    ];
}

test('the students index lists students this teacher teaches', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    claimStudentForTeacher($user->teacher, $school, 'Alice', 'Anderson');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Alice Anderson');
});

test('the students index only lists students linked to this teacher', function () {
    $user = makeStudentsIndexTeacherUser();
    $otherTeacher = Teacher::factory()->create();
    $school = School::factory()->create();

    claimStudentForTeacher($user->teacher, $school, 'Own', 'Student');
    claimStudentForTeacher($otherTeacher, $school, 'Other', 'Student');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Own Student')
        ->assertDontSee('Other Student');
});

test('students can be searched by name', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();

    claimStudentForTeacher($user->teacher, $school, 'Findme', 'Student');
    claimStudentForTeacher($user->teacher, $school, 'Skip', 'Student');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('search', 'Findme')
        ->assertSee('Findme Student')
        ->assertDontSee('Skip Student');
});

test('students can be sorted by name', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();

    claimStudentForTeacher($user->teacher, $school, 'Zeta', 'Student');
    claimStudentForTeacher($user->teacher, $school, 'Alpha', 'Student');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('sortBy', 'name')
        ->assertSet('sortColumn', 'name')
        ->assertSet('sortDirection', 'desc');
});

test('the students index shows the computed grade for the row\'s school', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();

    claimStudentForTeacher($user->teacher, $school, 'Grade', 'Nine', 9);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('9');
});

test('deactivate sets the student_teacher row is_active to false', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Active', 'Student');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('deactivate', $row->id);

    expect($row->refresh()->is_active)->toBeFalse();
});

test('deactivate cannot affect a row belonging to another teacher', function () {
    $user = makeStudentsIndexTeacherUser();
    $otherTeacher = Teacher::factory()->create();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($otherTeacher, $school, 'Not', 'Mine');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('deactivate', $row->id);

    expect($row->refresh()->is_active)->toBeTrue();
});

test('remove deletes the student_teacher row', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Gone', 'Student');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('remove', $row->id);

    expect(StudentTeacher::find($row->id))->toBeNull();
});

test('edit populates the form with the row\'s subject and role', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me', 9, 'chorus');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->assertSet('edit_subject', ['chorus'])
        ->assertSet('edit_role', 'primary');
});

test('saveEdit updates the subject and role on the row', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me', 9, 'chorus');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->set('edit_subject', ['orchestra'])
        ->set('edit_role', 'coteacher')
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveEdit')
        ->assertHasNoErrors();

    // Changing the only selected subject deletes the old (chorus) row and
    // creates a new one for orchestra, so the original $row no longer exists.
    $updatedRow = StudentTeacher::where('student_id', $row->student_id)
        ->where('teacher_id', $row->teacher_id)
        ->where('school_id', $row->school_id)
        ->first();

    expect($updatedRow)->not->toBeNull();
    expect($updatedRow->getRawOriginal('subject'))->toBe('orchestra');
    expect($updatedRow->getRawOriginal('role'))->toBe('coteacher');
});

test('saveEdit can claim a student under multiple subjects at once', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me', 9, 'chorus');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->set('edit_subject', ['chorus', 'orchestra'])
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveEdit')
        ->assertHasNoErrors();

    $rows = StudentTeacher::where('student_id', $row->student_id)
        ->where('teacher_id', $row->teacher_id)
        ->where('school_id', $row->school_id)
        ->get();

    expect($rows)->toHaveCount(2);
    expect($rows->map(fn ($r) => $r->getRawOriginal('subject'))->sort()->values()->all())
        ->toBe(['chorus', 'orchestra']);
});

test('saveEdit drops a subject row when its subject is deselected', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me', 9, 'chorus');
    StudentTeacher::create([
        'student_id' => $row->student_id,
        'teacher_id' => $row->teacher_id,
        'school_id' => $row->school_id,
        'subject' => 'orchestra',
        'role' => 'primary',
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->assertSet('edit_subject', ['chorus', 'orchestra'])
        ->set('edit_subject', ['chorus'])
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveEdit')
        ->assertHasNoErrors();

    $rows = StudentTeacher::where('student_id', $row->student_id)
        ->where('teacher_id', $row->teacher_id)
        ->where('school_id', $row->school_id)
        ->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->getRawOriginal('subject'))->toBe('chorus');
});

test('saveEdit updates the student\'s profile fields', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->set('edit_first_name', 'Updated')
        ->set('edit_last_name', 'Name')
        ->set('edit_email', 'updated@theschool.edu')
        ->set('edit_cell_phone', '5559876543')
        ->set('edit_birthday', '2012-04-01')
        ->set('edit_height', '60')
        ->set('edit_shirt_size', 'lg')
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveEdit')
        ->assertHasNoErrors();

    $student = $row->student->refresh();
    $user2 = $student->user->refresh();

    expect($user2->first_name)->toBe('Updated');
    expect($user2->last_name)->toBe('Name');
    expect($user2->email)->toBe('updated@theschool.edu');
    expect($user2->cell_phone)->toBe('5559876543');
    expect($user2->email_unverifiable)->toBeTrue();
    expect($student->height)->toBe(60);
    expect($student->getRawOriginal('shirt_size'))->toBe('lg');
});

test('saveEdit assigns a default email and shows a notice when the requested email is already taken', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me');

    $existingUser = User::factory()->create(['email' => 'taken@example.com']);

    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->set('edit_email', 'taken@example.com')
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveEdit')
        ->assertHasNoErrors();

    expect($component->get('emailFallbackNotice'))->not->toBeNull();

    $studentEmail = $row->student->user->refresh()->email;
    expect($studentEmail)->not->toBe('taken@example.com');
    expect($studentEmail)->toEndWith('@studentfolder.info');
});

test('saveEdit requires a pronoun', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->set('edit_pronoun_id', '')
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveEdit')
        ->assertHasErrors('edit_pronoun_id');
});

test('saveEdit rejects a height outside the 30-84 inch range', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->set('edit_height', '85')
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveEdit')
        ->assertHasErrors('edit_height');
});

test('saveEdit rejects a birthday that makes the student younger than 9', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->set('edit_birthday', now()->subYears(5)->format('Y-m-d'))
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveEdit')
        ->assertHasErrors('edit_birthday');
});

test('saveEdit rejects a birthday that makes the student older than 19', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->set('edit_birthday', now()->subYears(25)->format('Y-m-d'))
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveEdit')
        ->assertHasErrors('edit_birthday');
});

test('saveEdit accepts a birthday at the edges of the 9-19 age range', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->set('edit_birthday', now()->subYears(9)->format('Y-m-d'))
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveEdit')
        ->assertHasNoErrors();
});

test('studentAge computes the age shown in the Birthday label', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->set('edit_birthday', now()->subYears(12)->format('Y-m-d'))
        ->assertSee('12 years old');
});

test('the instrument field only shows for band or orchestra, and voice part only for chorus', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me', 9, 'band');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->assertSee('Instrument (optional)')
        ->assertDontSee('Voice part (optional)')
        ->set('edit_subject', ['chorus'])
        ->assertDontSee('Instrument (optional)')
        ->assertSee('Voice part (optional)');
});

test('switching subject away from band/orchestra clears the instrument selection', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me', 9, 'band');
    $instrument = Instrument::factory()->create();
    $row->student->update(['instrument_id' => $instrument->id]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->assertSet('edit_instrument_id', (string) $instrument->id)
        ->set('edit_subject', ['chorus'])
        ->assertSet('edit_instrument_id', '');
});

test('saveEdit assigns a default email when left blank', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->set('edit_email', '')
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveEdit')
        ->assertHasNoErrors();

    expect($row->student->user->refresh()->email)->toEndWith('@studentfolder.info');
});

test('saveEdit saves an optional home address when all fields are provided', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->set('edit_home_address1', '123 Main St')
        ->set('edit_home_city', 'Anytown')
        ->set('edit_home_geo_state', 'NJ')
        ->set('edit_home_zip_code', '08901')
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveEdit')
        ->assertHasNoErrors();

    $homeAddress = HomeAddress::where('student_id', $row->student_id)->first();
    expect($homeAddress)->not->toBeNull();
    expect($homeAddress->address1)->toBe('123 Main St');
    expect($homeAddress->city)->toBe('Anytown');
});

test('saveEdit requires every home address field once any one is filled in', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->set('edit_home_address1', '123 Main St')
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveEdit')
        ->assertHasErrors(['edit_home_city', 'edit_home_geo_state', 'edit_home_zip_code']);
});

test('saveEdit deletes the home address once every field is cleared', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me');
    HomeAddress::factory()->create(['student_id' => $row->student_id]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->set('edit_home_address1', '')
        ->set('edit_home_city', '')
        ->set('edit_home_geo_state', '')
        ->set('edit_home_zip_code', '')
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveEdit')
        ->assertHasNoErrors();

    expect(HomeAddress::where('student_id', $row->student_id)->exists())->toBeFalse();
});

test('saveEdit requires at least one emergency contact', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->set('edit_emergency_contacts', [])
        ->call('saveEdit')
        ->assertHasErrors('edit_emergency_contacts');
});

test('saveEdit syncs emergency contacts: updates existing, adds new, removes dropped', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me');

    $existing = EmergencyContact::factory()->create([
        'student_id' => $row->student_id,
        'name' => 'Original Name',
    ]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->set('edit_emergency_contacts', [
            [
                'id' => $existing->id,
                'name' => 'Renamed Contact',
                'relationship' => 'father',
                'email' => 'father@example.com',
                'cell_phone' => '5551112222',
                'home_phone' => '',
                'work_phone' => '',
            ],
            validEmergencyContact(),
        ])
        ->call('saveEdit')
        ->assertHasNoErrors();

    expect(EmergencyContact::where('student_id', $row->student_id)->count())->toBe(2);
    expect($existing->refresh()->name)->toBe('Renamed Contact');
});

test('resetPassword sets the password to the student\'s lowercase email', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me');
    $row->student->user->update(['email' => 'Student@TheSchool.edu']);

    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->call('resetPassword');

    $studentUser = $row->student->user->refresh();
    expect(Hash::check('student@theschool.edu', $studentUser->password))->toBeTrue();
    expect($component->get('passwordResetNotice'))->toBe('Password reset to the student\'s email address: student@theschool.edu.');
});

test('resetPassword cannot affect a student belonging to another teacher', function () {
    $user = makeStudentsIndexTeacherUser();
    $otherTeacher = Teacher::factory()->create();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($otherTeacher, $school, 'Not', 'Mine');

    // editingRowId is only ever set via edit(), which is itself scoped to the
    // teacher — simulating a tampered request to confirm resetPassword() re-checks
    // rather than trusting editingRowId. The teacher-scoped lookup fails to find
    // the row at all, so it throws instead of silently resetting someone else's.
    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('editingRowId', $row->id)
        ->call('resetPassword');
})->throws(ModelNotFoundException::class);
