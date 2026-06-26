<?php

declare(strict_types=1);

use App\Enums\ClaimStatus;
use App\Livewire\Students\Index;
use App\Mail\StudentClaimMail;
use App\Models\County;
use App\Models\EmergencyContact;
use App\Models\HomeAddress;
use App\Models\Instrument;
use App\Models\Pivots\SchoolStudent;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\SchoolTeacherSubject;
use App\Models\Pivots\StudentTeacher;
use App\Models\Pronoun;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\VoicePart;
use App\Support\ClassOfCalculator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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
 * given grade, then links them to the teacher for the given subject. Also ensures
 * the teacher has an active school_teacher pivot row there — the index gates the
 * roster to actively-attached schools, so without this a claimed student would be
 * invisible even though the student_teacher row itself exists.
 */
function claimStudentForTeacher(Teacher $teacher, School $school, string $firstName, string $lastName, int $grade = 9, string $subject = 'band'): StudentTeacher
{
    $student = Student::factory()->create();
    $student->user->update(['first_name' => $firstName, 'last_name' => $lastName]);

    $classOf = ClassOfCalculator::classOfFromGrade($grade, $school->senior_year);
    $school->students()->attach($student->id, ['is_active' => true, 'class_of' => $classOf]);

    if (! $teacher->schools()->where('schools.id', $school->id)->exists()) {
        $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    }

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
 * Records that a teacher is set up to teach a subject at a school, the way the
 * onboarding wizard would — used to test the Add-student Subject default.
 */
function addTeacherSubject(Teacher $teacher, School $school, string $subject): void
{
    $pivot = SchoolTeacher::where('teacher_id', $teacher->id)->where('school_id', $school->id)->first();

    SchoolTeacherSubject::create(['school_teacher_id' => $pivot->id, 'subject' => $subject]);
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
        ->assertSee('Anderson, Alice');
});

test('the Name column shows "Last Suffix, First Middle"', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Jane', 'Smith');
    $row->student->user->update(['middle_name' => 'Q', 'suffix_name' => 'Jr.']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Smith Jr., Jane Q');
});

test('the Name column omits absent middle name and suffix', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Jane', 'Smith');
    $row->student->user->update(['middle_name' => null, 'suffix_name' => null]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Smith, Jane')
        ->assertDontSee('Smith , Jane');
});

test('the students index shows a student\'s real email under their name', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Has', 'Email');
    $row->student->user->update(['email' => 'has.email@example.com']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('has.email@example.com')
        ->assertDontSee('No email address');
});

test('the students index shows "No email address" for a default studentfolder.info email', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Default', 'Email');
    $row->student->user->update(['email' => Str::uuid().'@studentfolder.info']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('No email address');
});

test('the students index shows "No email address" for a null email', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'No', 'Email');
    $row->student->user->update(['email' => null]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('No email address');
});

test('the students index shows a Yes badge when a home address or emergency contact exists', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Has', 'Address');
    HomeAddress::factory()->create(['student_id' => $row->student_id]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Yes');
});

test('the students index shows no Yes badge when home address and emergency contact are both absent', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    claimStudentForTeacher($user->teacher, $school, 'Has', 'Neither');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertDontSee('Yes')
        ->assertSee('No');
});

test('the students index shows a Yes badge when an emergency contact exists', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Has', 'Contact');
    EmergencyContact::factory()->create(['student_id' => $row->student_id]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Emergency Contact')
        ->assertSee('Yes');
});

test('the students index shows the voice part column after grade', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Has', 'VoicePart');
    $voicePart = VoicePart::factory()->create(['name' => 'Tenor']);
    $row->student->update(['voice_part_id' => $voicePart->id]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Tenor');
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

test('students can be sorted by grade ascending', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();

    claimStudentForTeacher($user->teacher, $school, 'Twelve', 'Student', 12);
    claimStudentForTeacher($user->teacher, $school, 'Four', 'Student', 4);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('sortBy', 'grade')
        ->assertSet('sortColumn', 'grade')
        ->assertSet('sortDirection', 'asc')
        ->assertSeeInOrder(['Student, Four', 'Student, Twelve']);
});

test('students can be sorted by grade descending', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();

    claimStudentForTeacher($user->teacher, $school, 'Twelve', 'Student', 12);
    claimStudentForTeacher($user->teacher, $school, 'Four', 'Student', 4);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('sortBy', 'grade')
        ->call('sortBy', 'grade')
        ->assertSet('sortDirection', 'desc')
        ->assertSeeInOrder(['Student, Twelve', 'Student, Four']);
});

test('students can be sorted by voice part', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();

    $rowA = claimStudentForTeacher($user->teacher, $school, 'A', 'Student', 9, 'chorus');
    $rowB = claimStudentForTeacher($user->teacher, $school, 'B', 'Student', 9, 'chorus');

    $tenor = VoicePart::factory()->create(['name' => 'Tenor']);
    $alto = VoicePart::factory()->create(['name' => 'Alto']);
    $rowA->student->update(['voice_part_id' => $tenor->id]);
    $rowB->student->update(['voice_part_id' => $alto->id]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('sortBy', 'voice_part')
        ->assertSet('sortColumn', 'voice_part')
        ->assertSet('sortDirection', 'asc')
        ->assertSeeInOrder(['Alto', 'Tenor']);
});

test('the schools filter is hidden when the teacher has only one active school', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    claimStudentForTeacher($user->teacher, $school, 'Solo', 'Student');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertDontSee('All schools');
});

test('the schools filter appears and filters the roster when the teacher has multiple active schools', function () {
    $user = makeStudentsIndexTeacherUser();
    $schoolOne = School::factory()->create(['name' => 'First School']);
    $schoolTwo = School::factory()->create(['name' => 'Second School']);

    claimStudentForTeacher($user->teacher, $schoolOne, 'At', 'First');
    claimStudentForTeacher($user->teacher, $schoolTwo, 'At', 'Second');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('All schools')
        ->assertSee('First, At')
        ->assertSee('Second, At')
        ->set('schoolFilter', (string) $schoolOne->id)
        ->assertSee('First, At')
        ->assertDontSee('Second, At');
});

test('students at a school the teacher has deactivated are hidden', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    claimStudentForTeacher($user->teacher, $school, 'Hidden', 'Student');

    $user->teacher->schools()->updateExistingPivot($school->id, ['is_active' => false]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertDontSee('Student, Hidden');
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
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', slots: ['text' => 'Updated Name updated successfully.']);

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

test('saveEdit rejects digits or symbols in first, middle, or last name', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->set('edit_first_name', 'Edit3')
        ->set('edit_middle_name', 'M1ddle')
        ->set('edit_last_name', 'Me!')
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveEdit')
        ->assertHasErrors(['edit_first_name', 'edit_middle_name', 'edit_last_name']);
});

test('saveEdit accepts hyphenated, multi-word, and apostrophe names', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->set('edit_first_name', 'Mary Jane')
        ->set('edit_last_name', "Smith-O'Brien")
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveEdit')
        ->assertHasNoErrors();
});

test('saveEdit does not show a success toast when the email fallback notice keeps the modal open', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me');
    User::factory()->create(['email' => 'taken@example.com']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->set('edit_email', 'taken@example.com')
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveEdit')
        ->assertHasNoErrors()
        ->assertNotDispatched('toast-show');
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
        ->assertHasErrors('edit_birthday')
        ->assertSee('The student must be at least 9 years old.')
        ->assertSee('This form has not been saved. Please fix the highlighted fields above before saving.');
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

test('add resets the form to a blank state and defaults role and shirt size', function () {
    $user = makeStudentsIndexTeacherUser();
    $schoolOne = School::factory()->create();
    $schoolTwo = School::factory()->create();
    $user->teacher->schools()->attach($schoolOne, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);
    $user->teacher->schools()->attach($schoolTwo, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);
    $row = claimStudentForTeacher($user->teacher, $schoolOne, 'Existing', 'Student');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->call('add')
        ->assertSet('editingRowId', null)
        ->assertSet('isAdding', true)
        ->assertSet('edit_first_name', '')
        ->assertSet('edit_subject', [])
        ->assertSet('edit_role', 'primary')
        ->assertSet('edit_shirt_size', 'med')
        // Two active schools means no unambiguous default.
        ->assertSet('add_school_id', '')
        ->assertSet('add_grade', '');
});

test('add defaults the school field when the teacher has only one active school', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $user->teacher->schools()->attach($school, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->assertSet('add_school_id', (string) $school->id);
});

test('add does not default the school field when the teacher has multiple active schools', function () {
    $user = makeStudentsIndexTeacherUser();
    $schoolOne = School::factory()->create();
    $schoolTwo = School::factory()->create();
    $user->teacher->schools()->attach($schoolOne, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);
    $user->teacher->schools()->attach($schoolTwo, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->assertSet('add_school_id', '');
});

test('add ignores an inactive school when defaulting the school field', function () {
    $user = makeStudentsIndexTeacherUser();
    $activeSchool = School::factory()->create();
    $inactiveSchool = School::factory()->create();
    $user->teacher->schools()->attach($activeSchool, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);
    $user->teacher->schools()->attach($inactiveSchool, ['role' => 'primary', 'is_active' => false]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->assertSet('add_school_id', (string) $activeSchool->id);
});

test('add defaults the subject field when the teacher only teaches one subject', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $user->teacher->schools()->attach($school, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);
    addTeacherSubject($user->teacher, $school, 'band');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->assertSet('edit_subject', ['band']);
});

test('add does not default the subject field when the teacher teaches multiple subjects', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $user->teacher->schools()->attach($school, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);
    addTeacherSubject($user->teacher, $school, 'band');
    addTeacherSubject($user->teacher, $school, 'chorus');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->assertSet('edit_subject', []);
});

test('saveAdd does not require an emergency contact', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $user->teacher->schools()->attach($school, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $school->id)
        ->set('add_grade', '9')
        ->set('edit_first_name', 'No')
        ->set('edit_last_name', 'Contact')
        ->set('edit_pronoun_id', (string) Pronoun::factory()->create()->id)
        ->set('edit_subject', ['band'])
        ->call('saveAdd')
        ->assertHasNoErrors();

    $user2 = User::where('first_name', 'No')->where('last_name', 'Contact')->firstOrFail();
    expect(EmergencyContact::where('student_id', $user2->student->id)->count())->toBe(0);
});

test('saveAdd still validates an emergency contact once the teacher starts filling one in', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $user->teacher->schools()->attach($school, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $school->id)
        ->set('add_grade', '9')
        ->set('edit_first_name', 'Partial')
        ->set('edit_last_name', 'Contact')
        ->set('edit_pronoun_id', (string) Pronoun::factory()->create()->id)
        ->set('edit_subject', ['band'])
        ->set('edit_emergency_contacts.0.name', 'Started Typing')
        ->call('saveAdd')
        ->assertHasErrors([
            'edit_emergency_contacts.0.relationship',
            'edit_emergency_contacts.0.email',
            'edit_emergency_contacts.0.cell_phone',
        ]);
});

test('saveAdd creates a new student with the chosen school, grade, and subject', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $user->teacher->schools()->attach($school, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $school->id)
        ->set('add_grade', '9')
        ->set('edit_first_name', 'New')
        ->set('edit_last_name', 'Student')
        ->set('edit_email', 'new.student@theschool.edu')
        ->set('edit_pronoun_id', (string) Pronoun::factory()->create()->id)
        ->set('edit_shirt_size', 'lg')
        ->set('edit_subject', ['band'])
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveAdd')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', slots: ['text' => 'New Student added successfully.']);

    $user2 = User::where('email', 'new.student@theschool.edu')->firstOrFail();
    $student = $user2->student;

    expect($student)->not->toBeNull();

    $schoolStudent = SchoolStudent::where('student_id', $student->id)->where('school_id', $school->id)->first();
    expect($schoolStudent)->not->toBeNull();
    expect((int) $schoolStudent->class_of)->toBe(ClassOfCalculator::classOfFromGrade(9, $school->senior_year));

    $studentTeacher = StudentTeacher::where('student_id', $student->id)
        ->where('teacher_id', $user->teacher->id)
        ->where('school_id', $school->id)
        ->first();
    expect($studentTeacher)->not->toBeNull();
    expect($studentTeacher->getRawOriginal('subject'))->toBe('band');
    expect($studentTeacher->getRawOriginal('role'))->toBe('primary');
});

test('saveAdd requires a school the teacher is actively attached to', function () {
    $user = makeStudentsIndexTeacherUser();
    $otherSchool = School::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $otherSchool->id)
        ->set('add_grade', '9')
        ->set('edit_first_name', 'New')
        ->set('edit_last_name', 'Student')
        ->set('edit_pronoun_id', (string) Pronoun::factory()->create()->id)
        ->set('edit_shirt_size', 'lg')
        ->set('edit_subject', ['band'])
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveAdd')
        ->assertHasErrors('add_school_id');
});

test('saveAdd requires a grade', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $user->teacher->schools()->attach($school, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $school->id)
        ->set('edit_first_name', 'New')
        ->set('edit_last_name', 'Student')
        ->set('edit_pronoun_id', (string) Pronoun::factory()->create()->id)
        ->set('edit_shirt_size', 'lg')
        ->set('edit_subject', ['band'])
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveAdd')
        ->assertHasErrors('add_grade');
});

test('saveAdd assigns a default email when left blank', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $user->teacher->schools()->attach($school, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $school->id)
        ->set('add_grade', '9')
        ->set('edit_first_name', 'New')
        ->set('edit_last_name', 'Student')
        ->set('edit_pronoun_id', (string) Pronoun::factory()->create()->id)
        ->set('edit_shirt_size', 'lg')
        ->set('edit_subject', ['band'])
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveAdd')
        ->assertHasNoErrors();

    $user2 = User::where('first_name', 'New')->where('last_name', 'Student')->firstOrFail();
    expect($user2->email)->toEndWith('@studentfolder.info');
});

test('the Add-student School label becomes Studio once a studio is selected', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $studio = School::factory()->studio()->create();
    $user->teacher->schools()->attach($school, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);
    $user->teacher->schools()->attach($studio, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $school->id)
        ->assertDontSee('Student\'s School')
        ->set('add_school_id', (string) $studio->id)
        ->assertSee('Student\'s School');
});

test('the Edit-student modal flags a studio row as a studio context', function () {
    $user = makeStudentsIndexTeacherUser();
    $studio = School::factory()->studio()->create();
    $row = claimStudentForTeacher($user->teacher, $studio, 'Vera', 'Vocalist');

    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id);

    expect($component->get('editingSchoolIsStudio'))->toBeTrue();
    expect($component->get('editingSchoolName'))->toBe($studio->name);
    $component->assertSee('Student\'s School');
});

test('saveAdd requires a home school when adding a student to a studio', function () {
    $user = makeStudentsIndexTeacherUser();
    $studio = School::factory()->studio()->create();
    $user->teacher->schools()->attach($studio, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $studio->id)
        ->set('add_grade', '9')
        ->set('edit_first_name', 'Quillon')
        ->set('edit_last_name', 'Hawthorpe')
        ->set('edit_pronoun_id', (string) Pronoun::factory()->create()->id)
        ->set('edit_shirt_size', 'lg')
        ->set('edit_subject', ['chorus'])
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveAdd')
        ->assertHasErrors('edit_home_school_name');
});

test('saveAdd records the matched home school for a studio student', function () {
    $user = makeStudentsIndexTeacherUser();
    $studio = School::factory()->studio()->create();
    $homeSchool = School::factory()->create();
    $user->teacher->schools()->attach($studio, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $studio->id)
        ->set('add_grade', '9')
        ->set('edit_first_name', 'Quillon')
        ->set('edit_last_name', 'Hawthorpe')
        ->set('edit_pronoun_id', (string) Pronoun::factory()->create()->id)
        ->set('edit_shirt_size', 'lg')
        ->set('edit_subject', ['chorus'])
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->set('edit_home_school_name', $homeSchool->name)
        ->call('selectHomeSchool', $homeSchool->id)
        ->call('saveAdd')
        ->assertHasNoErrors();

    $student = User::where('first_name', 'Quillon')->where('last_name', 'Hawthorpe')->firstOrFail()->student;
    expect($student->home_school_id)->toBe($homeSchool->id);
});

test('saveAdd creates a new home school when the teacher confirms one', function () {
    $user = makeStudentsIndexTeacherUser();
    $studio = School::factory()->studio()->create();
    $county = County::factory()->create();
    $user->teacher->schools()->attach($studio, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $studio->id)
        ->set('add_grade', '9')
        ->set('edit_first_name', 'Quillon')
        ->set('edit_last_name', 'Hawthorpe')
        ->set('edit_pronoun_id', (string) Pronoun::factory()->create()->id)
        ->set('edit_shirt_size', 'lg')
        ->set('edit_subject', ['chorus'])
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->set('edit_home_school_name', 'Brand New High School')
        ->call('confirmNewHomeSchool')
        ->set('edit_home_school_city', 'Anytown')
        ->set('edit_home_school_zip_code', '08901')
        ->set('edit_home_school_county_id', (string) $county->id)
        ->call('saveAdd')
        ->assertHasNoErrors();

    $newSchool = School::where('name', 'Brand New High School')->firstOrFail();
    expect($newSchool->getRawOriginal('type'))->toBe('school');

    $student = User::where('first_name', 'Quillon')->where('last_name', 'Hawthorpe')->firstOrFail()->student;
    expect($student->home_school_id)->toBe($newSchool->id);
});

test('saveAdd does not require a home school when the school is not a studio', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $user->teacher->schools()->attach($school, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $school->id)
        ->set('add_grade', '9')
        ->set('edit_first_name', 'Quillon')
        ->set('edit_last_name', 'Hawthorpe')
        ->set('edit_pronoun_id', (string) Pronoun::factory()->create()->id)
        ->set('edit_shirt_size', 'lg')
        ->set('edit_subject', ['band'])
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveAdd')
        ->assertHasNoErrors();

    $student = User::where('first_name', 'Quillon')->where('last_name', 'Hawthorpe')->firstOrFail()->student;
    expect($student->home_school_id)->toBeNull();
});

test('saveEdit updates the home school for an existing studio student', function () {
    $user = makeStudentsIndexTeacherUser();
    $studio = School::factory()->studio()->create();
    $oldHomeSchool = School::factory()->create();
    $newHomeSchool = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $studio, 'Vera', 'Vocalist', subject: 'chorus');
    $row->student->update(['home_school_id' => $oldHomeSchool->id]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->call('changeHomeSchool')
        ->set('edit_home_school_name', $newHomeSchool->name)
        ->call('selectHomeSchool', $newHomeSchool->id)
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveEdit')
        ->assertHasNoErrors();

    expect($row->student->refresh()->home_school_id)->toBe($newHomeSchool->id);
});

test('saveAdd shows a weak name match but does not block submission in a school context', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $user->teacher->schools()->attach($school, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);
    $existing = Student::factory()->create();
    $existing->user->update(['first_name' => 'Wendel', 'last_name' => 'Quoxbury']);

    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $school->id)
        ->set('add_grade', '9')
        ->set('edit_first_name', 'Wendel')
        ->set('edit_last_name', 'Quoxbury')
        ->assertSee('This may already be one of your students');

    $component->set('edit_pronoun_id', (string) Pronoun::factory()->create()->id)
        ->set('edit_shirt_size', 'lg')
        ->set('edit_subject', ['band'])
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveAdd')
        ->assertHasNoErrors();
});

test('saveAdd blocks on a weak name match until resolved in a studio context', function () {
    $user = makeStudentsIndexTeacherUser();
    $studio = School::factory()->studio()->create();
    $user->teacher->schools()->attach($studio, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);
    $existing = Student::factory()->create();
    $existing->user->update(['first_name' => 'Wendel', 'last_name' => 'Quoxbury']);

    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $studio->id)
        ->set('add_grade', '9')
        ->set('edit_first_name', 'Wendel')
        ->set('edit_last_name', 'Quoxbury')
        ->set('edit_pronoun_id', (string) Pronoun::factory()->create()->id)
        ->set('edit_shirt_size', 'lg')
        ->set('edit_subject', ['chorus'])
        ->set('edit_home_school_name', 'Quoxbury Family School')
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveAdd')
        ->assertHasErrors('edit_first_name');

    $component->call('dismissStudentMatch', $existing->id)
        ->call('confirmNewHomeSchool')
        ->set('edit_home_school_city', 'Anytown')
        ->set('edit_home_school_zip_code', '08901')
        ->set('edit_home_school_county_id', (string) County::factory()->create()->id)
        ->call('saveAdd')
        ->assertHasNoErrors();
});

test('saveAdd blocks on a strong match (same name and birthday) even in a school context', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $user->teacher->schools()->attach($school, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);
    $existing = Student::factory()->create(['birthday' => '2012-04-01']);
    $existing->user->update(['first_name' => 'Wendel', 'last_name' => 'Quoxbury']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $school->id)
        ->set('add_grade', '9')
        ->set('edit_first_name', 'Wendel')
        ->set('edit_last_name', 'Quoxbury')
        ->set('edit_birthday', '2012-04-01')
        ->set('edit_pronoun_id', (string) Pronoun::factory()->create()->id)
        ->set('edit_shirt_size', 'lg')
        ->set('edit_subject', ['band'])
        ->set('edit_emergency_contacts', [validEmergencyContact()])
        ->call('saveAdd')
        ->assertHasErrors('edit_first_name');
});

test('a matched student already at the school being added to offers to attach instead of a claim request', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $otherTeacherUser = makeStudentsIndexTeacherUser();
    $row = claimStudentForTeacher($otherTeacherUser->teacher, $school, 'Wendel', 'Quoxbury');
    $user->teacher->schools()->attach($school, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $school->id)
        ->set('edit_first_name', 'Wendel')
        ->set('edit_last_name', 'Quoxbury')
        ->assertSee('This is my student')
        ->assertDontSee('Request to add');
});

test('a matched student enrolled at a different school offers a claim request instead of attach', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $otherSchool = School::factory()->create();
    $otherTeacherUser = makeStudentsIndexTeacherUser();
    claimStudentForTeacher($otherTeacherUser->teacher, $otherSchool, 'Wendel', 'Quoxbury');
    $user->teacher->schools()->attach($school, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $school->id)
        ->set('edit_first_name', 'Wendel')
        ->set('edit_last_name', 'Quoxbury')
        ->assertDontSee('This is my student')
        ->assertSee('Request to add');
});

test('attaching to an existing same-school student claims them without creating a duplicate', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $otherTeacherUser = makeStudentsIndexTeacherUser();
    $existingRow = claimStudentForTeacher($otherTeacherUser->teacher, $school, 'Wendel', 'Quoxbury', subject: 'band');
    $user->teacher->schools()->attach($school, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);

    $userCountBefore = User::count();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $school->id)
        ->set('edit_first_name', 'Wendel')
        ->set('edit_last_name', 'Quoxbury')
        ->call('selectStudentMatch', $existingRow->student_id)
        ->set('edit_subject', ['chorus'])
        ->set('edit_role', 'coteacher')
        ->call('attachExistingStudent')
        ->assertHasNoErrors();

    expect(User::count())->toBe($userCountBefore);

    $newClaim = StudentTeacher::where('student_id', $existingRow->student_id)
        ->where('teacher_id', $user->teacher->id)
        ->where('school_id', $school->id)
        ->first();

    expect($newClaim)->not->toBeNull();
    expect($newClaim->getRawOriginal('subject'))->toBe('chorus');
    expect($newClaim->getRawOriginal('role'))->toBe('coteacher');
});

test('attachExistingStudent requires a subject', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $otherTeacherUser = makeStudentsIndexTeacherUser();
    $existingRow = claimStudentForTeacher($otherTeacherUser->teacher, $school, 'Wendel', 'Quoxbury');
    $user->teacher->schools()->attach($school, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $school->id)
        ->set('edit_first_name', 'Wendel')
        ->set('edit_last_name', 'Quoxbury')
        ->call('selectStudentMatch', $existingRow->student_id)
        ->set('edit_subject', [])
        ->call('attachExistingStudent')
        ->assertHasErrors('edit_subject');
});

test('a student already on the teacher own roster at that school is not suggested as a possible duplicate', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    claimStudentForTeacher($user->teacher, $school, 'Wendel', 'Quoxbury', subject: 'chorus');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $school->id)
        ->set('edit_first_name', 'Wendel')
        ->set('edit_last_name', 'Quoxbury')
        ->assertDontSee('This may already be one of your students');
});

test('attachExistingStudent does not error when the teacher already claims that subject (regression)', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $existingRow = claimStudentForTeacher($user->teacher, $school, 'Wendel', 'Quoxbury', subject: 'chorus');

    // The teacher already has this exact (student, teacher, school, subject)
    // row — selectStudentMatch()/attachExistingStudent() are called directly
    // here (bypassing the suggestion-list exclusion that normally prevents
    // this) to pin down the underlying bug: attaching used to insert
    // unconditionally and crash on student_teacher's unique constraint.
    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $school->id)
        ->call('selectStudentMatch', $existingRow->student_id)
        ->set('edit_subject', ['chorus'])
        ->set('edit_role', 'primary')
        ->call('attachExistingStudent')
        ->assertHasNoErrors();

    expect(StudentTeacher::where('student_id', $existingRow->student_id)
        ->where('teacher_id', $user->teacher->id)
        ->where('school_id', $school->id)
        ->where('subject', 'chorus')
        ->count())->toBe(1);
});

test('attachExistingStudent reactivates a previously deactivated subject claim', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $existingRow = claimStudentForTeacher($user->teacher, $school, 'Wendel', 'Quoxbury', subject: 'chorus');
    $existingRow->update(['is_active' => false]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $school->id)
        ->call('selectStudentMatch', $existingRow->student_id)
        ->set('edit_subject', ['chorus'])
        ->set('edit_role', 'primary')
        ->call('attachExistingStudent')
        ->assertHasNoErrors();

    expect($existingRow->refresh()->is_active)->toBeTrue();
});

test('attachExistingStudent creates only the new subject when one is already claimed', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $existingRow = claimStudentForTeacher($user->teacher, $school, 'Wendel', 'Quoxbury', subject: 'chorus');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $school->id)
        ->call('selectStudentMatch', $existingRow->student_id)
        ->set('edit_subject', ['chorus', 'band'])
        ->set('edit_role', 'primary')
        ->call('attachExistingStudent')
        ->assertHasNoErrors();

    $subjects = StudentTeacher::where('student_id', $existingRow->student_id)
        ->where('teacher_id', $user->teacher->id)
        ->where('school_id', $school->id)
        ->get()
        ->map(fn (StudentTeacher $row) => $row->getRawOriginal('subject'))
        ->sort()
        ->values()
        ->all();

    expect($subjects)->toBe(['band', 'chorus']);
});

// Phase 2: cross-org claim (pending approval) tests

test('submitStudentClaim auto-approves when the matched student has no active teachers', function () {
    Mail::fake();

    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $user->teacher->schools()->attach($school, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);
    // Student exists in the system but has no active student_teacher rows (orphaned).
    $orphan = Student::factory()->create();
    SchoolStudent::create(['student_id' => $orphan->id, 'school_id' => $school->id, 'is_active' => true, 'class_of' => 2026]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $school->id)
        ->call('selectStudentClaim', $orphan->id)
        ->set('claim_grade', '9')
        ->set('edit_subject', ['chorus'])
        ->set('edit_role', 'primary')
        ->call('submitStudentClaim')
        ->assertHasNoErrors();

    $row = StudentTeacher::where('student_id', $orphan->id)
        ->where('teacher_id', $user->teacher->id)
        ->where('school_id', $school->id)
        ->first();

    expect($row)->not->toBeNull();
    expect($row->getRawOriginal('claim_status'))->toBe(ClaimStatus::Approved->value);
    expect($row->is_active)->toBeTrue();

    Mail::assertNothingSent();
});

test('submitStudentClaim creates pending rows and emails the existing teacher', function () {
    Mail::fake();

    $user = makeStudentsIndexTeacherUser();
    $studio = School::factory()->studio()->create();
    $user->teacher->schools()->attach($studio, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);
    $existingTeacherUser = makeStudentsIndexTeacherUser();
    $existingTeacherUser->update(['email' => 'existing.teacher@school.edu']);
    $existingRow = claimStudentForTeacher($existingTeacherUser->teacher, School::factory()->create(), 'Wendel', 'Quoxbury', subject: 'chorus');
    $homeSchoolId = School::factory()->create()->id;
    $existingRow->student->update(['home_school_id' => $homeSchoolId]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add')
        ->set('add_school_id', (string) $studio->id)
        ->call('selectStudentClaim', $existingRow->student_id)
        ->set('claim_grade', '10')
        ->set('edit_subject', ['chorus'])
        ->set('edit_role', 'primary')
        ->call('submitStudentClaim')
        ->assertHasNoErrors();

    $pending = StudentTeacher::where('student_id', $existingRow->student_id)
        ->where('teacher_id', $user->teacher->id)
        ->where('school_id', $studio->id)
        ->first();

    expect($pending)->not->toBeNull();
    expect($pending->getRawOriginal('claim_status'))->toBe(ClaimStatus::Pending->value);
    expect($pending->is_active)->toBeFalse();
    expect($pending->pending_class_of)->toBe(
        ClassOfCalculator::classOfFromGrade(10, $studio->senior_year)
    );

    expect(SchoolStudent::where('student_id', $existingRow->student_id)->where('school_id', $studio->id)->exists())->toBeFalse();

    Mail::assertSent(StudentClaimMail::class, fn (StudentClaimMail $mail) => $mail->hasTo('existing.teacher@school.edu'));
});

// Controller route tests for student-claim.approve / .deny live in
// tests/Feature/StudentClaimControllerTest.php (class-based, for proper $this->get() typing).

test('a pending row shows a Pending badge in the students index', function () {
    $user = makeStudentsIndexTeacherUser();
    $studio = School::factory()->create();
    $existingTeacherUser = makeStudentsIndexTeacherUser();
    $existingRow = claimStudentForTeacher($existingTeacherUser->teacher, School::factory()->create(), 'Wendel', 'Quoxbury');
    SchoolStudent::create(['student_id' => $existingRow->student_id, 'school_id' => $studio->id, 'is_active' => true, 'class_of' => 2026]);
    $user->teacher->schools()->attach($studio, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);

    StudentTeacher::create([
        'student_id' => $existingRow->student_id,
        'teacher_id' => $user->teacher->id,
        'school_id' => $studio->id,
        'subject' => 'chorus',
        'role' => 'primary',
        'is_active' => false,
        'claim_status' => ClaimStatus::Pending->value,
    ]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Pending');
});

test('edit() refuses to open a pending row and shows a warning toast', function () {
    $user = makeStudentsIndexTeacherUser();
    $studio = School::factory()->create();
    $existingTeacherUser = makeStudentsIndexTeacherUser();
    $existingRow = claimStudentForTeacher($existingTeacherUser->teacher, School::factory()->create(), 'Wendel', 'Quoxbury');
    $user->teacher->schools()->attach($studio, ['role' => 'primary', 'is_active' => true, 'verified_at' => now()]);

    $pendingRow = StudentTeacher::create([
        'student_id' => $existingRow->student_id,
        'teacher_id' => $user->teacher->id,
        'school_id' => $studio->id,
        'subject' => 'chorus',
        'role' => 'primary',
        'is_active' => false,
        'claim_status' => ClaimStatus::Pending->value,
    ]);

    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $pendingRow->id);

    expect($component->get('editingRowId'))->toBeNull();
    expect($component->get('edit_first_name'))->toBe('');
});
