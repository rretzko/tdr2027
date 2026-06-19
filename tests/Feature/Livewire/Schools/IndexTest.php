<?php

declare(strict_types=1);

use App\Livewire\Schools\Index;
use App\Mail\SchoolEmailVerificationMail;
use App\Models\County;
use App\Models\Geostate;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\StudentTeacher;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

function makeOnboardedTeacherUser(): User
{
    $user = User::factory()->create();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    return $user;
}

test('the schools index page lists schools in a table', function () {
    $user = makeOnboardedTeacherUser();
    $school = School::factory()->create(['name' => 'Zzyzx Unique Academy']);

    $user->teacher->schools()->attach($school);

    actingAs($user)->get(route('schools.index', ['search' => $school->name]))
        ->assertOk()
        ->assertSee($school->name);
});

test('the schools index only lists schools the teacher is attached to', function () {
    $user = makeOnboardedTeacherUser();
    $ownSchool = School::factory()->create(['name' => 'Lincoln High School']);
    $otherSchool = School::factory()->create(['name' => 'Roosevelt High School']);

    $user->teacher->schools()->attach($ownSchool);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Lincoln High School')
        ->assertDontSee('Roosevelt High School');
});

test('schools can be searched by name', function () {
    $user = makeOnboardedTeacherUser();
    $lincoln = School::factory()->create(['name' => 'Lincoln High School']);
    $roosevelt = School::factory()->create(['name' => 'Roosevelt High School']);

    $user->teacher->schools()->attach([$lincoln->id, $roosevelt->id]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('search', 'Lincoln')
        ->assertSee('Lincoln High School')
        ->assertDontSee('Roosevelt High School');
});

test('schools can be sorted by name', function () {
    $user = makeOnboardedTeacherUser();
    $roosevelt = School::factory()->create(['name' => 'Roosevelt High School']);
    $lincoln = School::factory()->create(['name' => 'Lincoln High School']);

    $user->teacher->schools()->attach([$roosevelt->id, $lincoln->id]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('sortBy', 'name')
        ->assertSet('sortColumn', 'name')
        ->assertSet('sortDirection', 'desc');
});

test('deactivate sets the school_teacher pivot is_active to false', function () {
    $user = makeOnboardedTeacherUser();
    $school = School::factory()->create();

    $user->teacher->schools()->attach($school, ['is_active' => true]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('deactivate', $school->id);

    expect($user->teacher->schools()->find($school->id)->pivot->is_active)->toBeFalse();
});

test('a school can be removed when no students are linked to the teacher there', function () {
    $user = makeOnboardedTeacherUser();
    $school = School::factory()->create();

    $user->teacher->schools()->attach($school);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('remove', $school->id)
        ->assertHasNoErrors();

    expect($user->teacher->schools()->find($school->id))->toBeNull();
});

test('a school cannot be removed while a student is linked to the teacher there', function () {
    $user = makeOnboardedTeacherUser();
    $school = School::factory()->create();
    $student = Student::factory()->create();

    $user->teacher->schools()->attach($school);

    StudentTeacher::factory()->create([
        'teacher_id' => $user->teacher->id,
        'student_id' => $student->id,
        'school_id' => $school->id,
    ]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('remove', $school->id)
        ->assertHasErrors('remove');

    expect($user->teacher->schools()->find($school->id))->not->toBeNull();
});

test('edit populates the form with the school and pivot data', function () {
    $user = makeOnboardedTeacherUser();
    $school = School::factory()->create(['name' => 'Lincoln High School', 'city' => 'Lincoln']);

    $user->teacher->schools()->attach($school, [
        'role' => 'coteacher',
        'replacing_teacher_name' => 'Mr. Smith',
        'school_email' => 'teacher@lincoln.edu',
    ]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $school->id)
        ->assertSet('edit_name', 'Lincoln High School')
        ->assertSet('edit_city', 'Lincoln')
        ->assertSet('edit_role', 'coteacher')
        ->assertSet('edit_is_replacing_teacher', true)
        ->assertSet('edit_replacing_teacher_name', 'Mr. Smith')
        ->assertSet('edit_school_email', 'teacher@lincoln.edu');
});

test('saveEdit updates both the school record and the school_teacher pivot', function () {
    $user = makeOnboardedTeacherUser();
    $geostate = Geostate::factory()->create();
    $county = County::factory()->create(['geostate_id' => $geostate->id]);
    $school = School::factory()->create(['name' => 'Old Name', 'city' => 'Old City']);

    $user->teacher->schools()->attach($school, ['role' => 'primary']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $school->id)
        ->set('edit_name', 'New Name')
        ->set('edit_city', 'New City')
        ->set('edit_geostate_id', (string) $geostate->id)
        ->set('edit_county_id', (string) $county->id)
        ->set('edit_role', 'coteacher')
        ->call('saveEdit')
        ->assertHasNoErrors();

    $school->refresh();
    expect($school->name)->toBe('New Name');
    expect($school->city)->toBe('New City');
    expect($school->county_id)->toBe($county->id);

    $pivot = $user->teacher->schools()->find($school->id)->pivot;
    expect($pivot->getRawOriginal('role'))->toBe('coteacher');
});

test('saveEdit shows a personalized success toast', function () {
    $user = makeOnboardedTeacherUser();
    $school = School::factory()->create(['name' => 'Old Name']);

    $user->teacher->schools()->attach($school, ['role' => 'primary']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $school->id)
        ->set('edit_name', 'New Name')
        ->call('saveEdit')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', slots: ['text' => 'New Name updated successfully.']);
});

test('saveEdit requires a replacing teacher name when replacing a teacher', function () {
    $user = makeOnboardedTeacherUser();
    $school = School::factory()->create();

    $user->teacher->schools()->attach($school);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $school->id)
        ->set('edit_is_replacing_teacher', true)
        ->set('edit_replacing_teacher_name', '')
        ->call('saveEdit')
        ->assertHasErrors('edit_replacing_teacher_name');
});

test('a school with no school_email shows the missing-email message', function () {
    $user = makeOnboardedTeacherUser();
    $school = School::factory()->create();

    $user->teacher->schools()->attach($school, ['school_email' => null]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('No school email found');
});

test('a school with an unverified school_email shows a Pending badge', function () {
    $user = makeOnboardedTeacherUser();
    $school = School::factory()->create();

    $user->teacher->schools()->attach($school, ['school_email' => 'teacher@school.edu', 'verified_at' => null]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('teacher@school.edu')
        ->assertSee('Pending')
        ->assertDontSee('Verified');
});

test('a school with a verified school_email shows a Verified badge', function () {
    $user = makeOnboardedTeacherUser();
    $school = School::factory()->create();

    $user->teacher->schools()->attach($school, ['school_email' => 'teacher@school.edu', 'verified_at' => now()]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Verified');
});

test('schoolEmailDomainWarning flags a commercial provider while typing', function () {
    $user = makeOnboardedTeacherUser();
    $school = School::factory()->create();

    $user->teacher->schools()->attach($school);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $school->id)
        ->set('edit_school_email', 'teacher@gmail.com')
        ->assertSee('personal email provider');
});

test('schoolEmailDomainWarning is silent for a non-commercial domain', function () {
    $user = makeOnboardedTeacherUser();
    $school = School::factory()->create();

    $user->teacher->schools()->attach($school);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $school->id)
        ->set('edit_school_email', 'teacher@theschool.edu')
        ->assertDontSee('personal email provider');
});

test('saveEdit rejects a commercial email domain for school_email', function () {
    $user = makeOnboardedTeacherUser();
    $school = School::factory()->create();

    $user->teacher->schools()->attach($school);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $school->id)
        ->set('edit_school_email', 'teacher@yahoo.com')
        ->call('saveEdit')
        ->assertHasErrors('edit_school_email');
});

test('saveEdit sends a verification email when school_email is newly added', function () {
    Mail::fake();

    $user = makeOnboardedTeacherUser();
    $school = School::factory()->create();

    $user->teacher->schools()->attach($school, ['role' => 'primary', 'school_email' => null]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $school->id)
        ->set('edit_school_email', 'teacher@theschool.edu')
        ->call('saveEdit')
        ->assertHasNoErrors();

    Mail::assertSent(SchoolEmailVerificationMail::class, fn ($mail) => $mail->hasTo('teacher@theschool.edu'));

    $pivot = SchoolTeacher::where('school_id', $school->id)->where('teacher_id', $user->teacher->id)->first();
    expect($pivot->school_email)->toBe('teacher@theschool.edu');
    expect($pivot->verified_at)->toBeNull();
});

test('saveEdit does not send mail when school_email is unchanged', function () {
    Mail::fake();

    $user = makeOnboardedTeacherUser();
    $school = School::factory()->create();

    $user->teacher->schools()->attach($school, ['role' => 'primary', 'school_email' => 'teacher@theschool.edu', 'verified_at' => now()]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $school->id)
        ->set('edit_city', 'Somewhere Else')
        ->call('saveEdit')
        ->assertHasNoErrors();

    Mail::assertNothingSent();

    $pivot = SchoolTeacher::where('school_id', $school->id)->where('teacher_id', $user->teacher->id)->first();
    expect($pivot->verified_at)->not->toBeNull();
});

test('saveEdit treats a stray empty-string school_email the same as null', function () {
    Mail::fake();

    $user = makeOnboardedTeacherUser();
    $school = School::factory()->create();

    // Simulates school_email having been left as '' by a direct database edit
    // rather than NULL — saving with the field still blank should be a no-op.
    $user->teacher->schools()->attach($school, ['role' => 'primary', 'school_email' => '', 'verified_at' => now()]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $school->id)
        ->set('edit_city', 'Somewhere Else')
        ->call('saveEdit')
        ->assertHasNoErrors();

    Mail::assertNothingSent();

    $pivot = SchoolTeacher::where('school_id', $school->id)->where('teacher_id', $user->teacher->id)->first();
    expect($pivot->verified_at)->not->toBeNull();
});

test('saveEdit auto-verifies school_email when it matches the teacher\'s own verified account email', function () {
    Mail::fake();

    $user = makeOnboardedTeacherUser();
    $school = School::factory()->create();

    $user->teacher->schools()->attach($school, ['role' => 'primary', 'school_email' => null]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $school->id)
        ->set('edit_school_email', $user->email)
        ->call('saveEdit')
        ->assertHasNoErrors();

    Mail::assertNothingSent();

    $pivot = SchoolTeacher::where('school_id', $school->id)->where('teacher_id', $user->teacher->id)->first();
    expect($pivot->school_email)->toBe($user->email);
    expect($pivot->verified_at)->not->toBeNull();
});

test('saveEdit does not auto-verify when it matches the account email but that email is unverified', function () {
    Mail::fake();

    $user = User::factory()->unverified()->create();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
    $school = School::factory()->create();

    $user->teacher->schools()->attach($school, ['role' => 'primary', 'school_email' => null]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $school->id)
        ->set('edit_school_email', $user->email)
        ->call('saveEdit')
        ->assertHasNoErrors();

    Mail::assertSent(SchoolEmailVerificationMail::class);

    $pivot = SchoolTeacher::where('school_id', $school->id)->where('teacher_id', $user->teacher->id)->first();
    expect($pivot->verified_at)->toBeNull();
});

test('visiting a valid signed verification link marks the pivot verified', function () {
    $user = makeOnboardedTeacherUser();
    $school = School::factory()->create();

    $user->teacher->schools()->attach($school, ['school_email' => 'teacher@theschool.edu']);
    $pivot = SchoolTeacher::where('school_id', $school->id)->where('teacher_id', $user->teacher->id)->first();

    $url = URL::temporarySignedRoute(
        'school-email.verify',
        now()->addDays(3),
        ['schoolTeacher' => $pivot->id, 'email' => 'teacher@theschool.edu'],
    );

    get($url)->assertOk();

    expect($pivot->refresh()->verified_at)->not->toBeNull();
});

test('a verification link for a stale email no longer matches and is rejected', function () {
    $user = makeOnboardedTeacherUser();
    $school = School::factory()->create();

    $user->teacher->schools()->attach($school, ['school_email' => 'teacher@theschool.edu']);
    $pivot = SchoolTeacher::where('school_id', $school->id)->where('teacher_id', $user->teacher->id)->first();

    $url = URL::temporarySignedRoute(
        'school-email.verify',
        now()->addDays(3),
        ['schoolTeacher' => $pivot->id, 'email' => 'old-address@theschool.edu'],
    );

    get($url)->assertNotFound();

    expect($pivot->refresh()->verified_at)->toBeNull();
});

test('saveEdit rejects a school name already used at the same zip code', function () {
    $user = makeOnboardedTeacherUser();
    $school = School::factory()->create(['zip_code' => '12345']);
    School::factory()->create(['name' => 'Taken Name', 'zip_code' => '12345']);

    $user->teacher->schools()->attach($school);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $school->id)
        ->set('edit_name', 'Taken Name')
        ->call('saveEdit')
        ->assertHasErrors('edit_name');
});
