<?php

declare(strict_types=1);

use App\Enums\LoginMethod;
use App\Livewire\Founder\RemoveBotRegistrations;
use App\Models\Candidate;
use App\Models\LoginEvent;
use App\Models\PageVisit;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

// makeFounderUser() is already declared globally by ImpersonateTest.php —
// Pest loads every test file into one process for a full-suite run, so
// redeclaring it here would fatal-error the whole suite, not just this file.

function makeStaleUnverifiedUser(array $overrides = []): User
{
    return User::factory()->unverified()->create(array_merge([
        'created_at' => now()->subDays(5),
    ], $overrides));
}

test('a non-founder cannot view the remove bot registrations page', function () {
    $user = User::factory()->create();

    actingAs($user)->get(route('founder.remove-bot-registrations'))->assertNotFound();
});

test('the founder can view the remove bot registrations page', function () {
    $founder = makeFounderUser();

    actingAs($founder)->get(route('founder.remove-bot-registrations'))
        ->assertOk()
        ->assertSeeText('Remove Bot-registrations');
});

test('a student registration with no verification, login, page visit, or school is flagged', function () {
    $founder = makeFounderUser();
    $user = makeStaleUnverifiedUser();
    Student::factory()->for($user)->create();

    Livewire::actingAs($founder)
        ->test(RemoveBotRegistrations::class)
        ->assertSee($user->email);
});

test('a user with a verified email is not flagged', function () {
    $founder = makeFounderUser();
    $user = User::factory()->create(['created_at' => now()->subDays(5)]);
    Student::factory()->for($user)->create();

    Livewire::actingAs($founder)
        ->test(RemoveBotRegistrations::class)
        ->assertDontSee($user->email);
});

test('a user with a recorded login is not flagged', function () {
    $founder = makeFounderUser();
    $user = makeStaleUnverifiedUser();
    Student::factory()->for($user)->create();
    LoginEvent::record($user, LoginMethod::Email);

    Livewire::actingAs($founder)
        ->test(RemoveBotRegistrations::class)
        ->assertDontSee($user->email);
});

test('a user with a recorded page visit is not flagged', function () {
    $founder = makeFounderUser();
    $user = makeStaleUnverifiedUser();
    Student::factory()->for($user)->create();
    PageVisit::factory()->for($user)->create();

    Livewire::actingAs($founder)
        ->test(RemoveBotRegistrations::class)
        ->assertDontSee($user->email);
});

test('a student who has joined a school is not flagged', function () {
    $founder = makeFounderUser();
    $user = makeStaleUnverifiedUser();
    $student = Student::factory()->for($user)->create();
    $school = School::factory()->create();
    $student->schools()->attach($school->id, ['is_active' => true, 'class_of' => now()->year + 1]);

    Livewire::actingAs($founder)
        ->test(RemoveBotRegistrations::class)
        ->assertDontSee($user->email);
});

test('a registration younger than the minimum age is not flagged yet', function () {
    $founder = makeFounderUser();
    $user = User::factory()->unverified()->create();
    Student::factory()->for($user)->create();

    Livewire::actingAs($founder)
        ->test(RemoveBotRegistrations::class)
        ->assertDontSee($user->email);
});

test('removeSelected permanently deletes the flagged user and their student record', function () {
    $founder = makeFounderUser();
    $user = makeStaleUnverifiedUser();
    $student = Student::factory()->for($user)->create();

    Livewire::actingAs($founder)
        ->test(RemoveBotRegistrations::class)
        ->set('selected', [$user->id])
        ->call('removeSelected');

    expect(User::find($user->id))->toBeNull();
    expect(Student::find($student->id))->toBeNull();
});

test('removeSelected leaves accounts that were not selected untouched', function () {
    $founder = makeFounderUser();
    $flagged = makeStaleUnverifiedUser();
    Student::factory()->for($flagged)->create();
    $untouched = makeStaleUnverifiedUser();
    Student::factory()->for($untouched)->create();

    Livewire::actingAs($founder)
        ->test(RemoveBotRegistrations::class)
        ->set('selected', [$flagged->id])
        ->call('removeSelected');

    expect(User::find($flagged->id))->toBeNull();
    expect(User::find($untouched->id))->not->toBeNull();
});

test('a teacher who left teaching but has candidate history is not flagged, even with no current school link', function () {
    $founder = makeFounderUser();
    $user = makeStaleUnverifiedUser();
    $teacher = Teacher::factory()->for($user)->create();
    // No school_teacher pivot at all — as if the teacher was fully removed
    // after leaving — but real audition history remains.
    actingAs($founder);
    Candidate::factory()->create(['teacher_id' => $teacher->id]);

    Livewire::actingAs($founder)
        ->test(RemoveBotRegistrations::class)
        ->assertDontSee($user->email);
});

test('a student with candidate history is not flagged, even with no current school link', function () {
    $founder = makeFounderUser();
    $user = makeStaleUnverifiedUser();
    $student = Student::factory()->for($user)->create();
    actingAs($founder);
    Candidate::factory()->create(['student_id' => $student->id]);

    Livewire::actingAs($founder)
        ->test(RemoveBotRegistrations::class)
        ->assertDontSee($user->email);
});

test('removeSelected refuses to delete a selected user who no longer matches the criteria', function () {
    $founder = makeFounderUser();
    $user = makeStaleUnverifiedUser();
    $teacher = Teacher::factory()->for($user)->create();
    actingAs($founder);
    Candidate::factory()->create(['teacher_id' => $teacher->id]);

    Livewire::actingAs($founder)
        ->test(RemoveBotRegistrations::class)
        ->set('selected', [$user->id])
        ->call('removeSelected');

    expect(User::find($user->id))->not->toBeNull();
    expect(Teacher::find($teacher->id))->not->toBeNull();
});

test('removeSelected deletes a flagged teacher and their teacher record', function () {
    $founder = makeFounderUser();
    $user = makeStaleUnverifiedUser();
    $teacher = Teacher::factory()->for($user)->create();

    Livewire::actingAs($founder)
        ->test(RemoveBotRegistrations::class)
        ->set('selected', [$user->id])
        ->call('removeSelected');

    expect(User::find($user->id))->toBeNull();
    expect(Teacher::find($teacher->id))->toBeNull();
});
