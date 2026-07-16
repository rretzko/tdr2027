<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Livewire\Registrations\VersionDashboard;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VoicePart;
use App\Services\EligibilityService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeRegistrationTeacher(): Teacher
{
    $user = User::factory()->create();

    return Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
}

function attachEligibleStudentToTeacher(Teacher $teacher, School $school): Student
{
    $student = Student::factory()->create();

    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    $student->schools()->attach($school->id, ['is_active' => true, 'class_of' => (int) date('Y') + 1]);
    $student->teachers()->attach($teacher->id, [
        'school_id' => $school->id,
        'subject' => 'chorus',
        'role' => 'primary',
        'is_active' => true,
    ]);

    return $student;
}

function inviteRegistrationTeacher(Teacher $teacher, Version $version, string $status = 'invited'): VersionInvitation
{
    return VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => $status,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);
}

test('mount displays the version name', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create(['name' => 'Fall Auditions']);
    inviteRegistrationTeacher($teacher, $version);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertSee('Fall Auditions');
});

test('mount redirects an eligible but uninvited teacher to the Request Invitation page', function () {
    $teacher = makeRegistrationTeacher();
    $school = School::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    $version = Version::factory()->create();

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertRedirect(route('registrations.request-invitation', $version));
});

test('mount aborts with 403 for an ineligible, uninvited teacher', function () {
    $teacher = makeRegistrationTeacher();
    // No active+verified school attached — fails the base eligibility gate.
    $version = Version::factory()->create();

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertStatus(403);
});

test('eligibleStudents (and its isNotInvited gate) is blocked for an uninvited teacher, even bypassing the page-level gate', function () {
    $teacher = makeRegistrationTeacher();
    $school = School::factory()->create();
    $student = attachEligibleStudentToTeacher($teacher, $school);
    $version = Version::factory()->create();

    // EligibilityService::eligibleStudents() is the defense-in-depth layer
    // behind VersionDashboard::mount()'s gate — assert it independently
    // returns nothing for an uninvited teacher, regardless of the page gate.
    expect(app(EligibilityService::class)->eligibleStudents($version, $teacher))->toBeEmpty();
    expect(app(EligibilityService::class)->isNotInvited($version, $teacher))->toBeTrue();
});

test('withdraw sets the candidate status to teacher_withdrawn', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);
    inviteRegistrationTeacher($teacher, $version);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Registered,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->call('withdraw', $candidate->id);

    expect($candidate->refresh()->status)->toBe(CandidateStatus::TeacherWithdrawn);
});

test('withdraw cannot target a candidate belonging to another teacher', function () {
    $teacher = makeRegistrationTeacher();
    $otherTeacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($otherTeacher->user);
    inviteRegistrationTeacher($teacher, $version);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $otherTeacher->id,
        'status' => CandidateStatus::Registered,
    ]);

    expect(function () use ($teacher, $version, $candidate) {
        Livewire::actingAs($teacher->user)
            ->test(VersionDashboard::class, ['version' => $version])
            ->call('withdraw', $candidate->id);
    })->toThrow(ModelNotFoundException::class);

    expect($candidate->refresh()->status)->toBe(CandidateStatus::Registered);
});

test('My Candidates is sorted by the student\'s alpha name order, not program_name', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    inviteRegistrationTeacher($teacher, $version);

    // Deliberately opposite: program_name order would put "Aardvark" first
    // and "Zebra" last, but sort_name order (Adams before Zeta) is the
    // reverse — proves the sort key is the student's name, not program_name.
    $adams = Student::factory()->create();
    $adams->user->update(['first_name' => 'Aaron', 'last_name' => 'Adams']);
    $zeta = Student::factory()->create();
    $zeta->user->update(['first_name' => 'Zoe', 'last_name' => 'Zeta']);

    actingAs($teacher->user);

    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'student_id' => $zeta->id, 'program_name' => 'Aardvark Program']);
    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'student_id' => $adams->id, 'program_name' => 'Zebra Program']);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertSeeInOrder(['Adams, Aaron', 'Zeta, Zoe']);
});

test('the voice part summary table counts only Registered candidates per voice part, with a Registered total column', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    inviteRegistrationTeacher($teacher, $version);

    // Attaches voice parts to the Version's Event via an Ensemble, so they
    // show up in Version::availableVoiceParts() — the "eligible ensemble
    // voice parts" the count table is scoped to, not every VoicePart in
    // the system.
    $ensemble = Ensemble::factory()->create(['event_id' => $version->event_id]);
    $soprano = VoicePart::factory()->create(['name' => 'Soprano', 'abbr' => 'SOP', 'sort_order' => 1]);
    $alto = VoicePart::factory()->create(['name' => 'Alto', 'abbr' => 'ALT', 'sort_order' => 2]);
    $ensemble->voiceParts()->attach([$soprano->id, $alto->id]);

    actingAs($teacher->user);

    // Soprano: 1 eligible (not counted) + 1 registered (counted) = 1.
    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'voice_part_id' => $soprano->id, 'status' => CandidateStatus::Eligible]);
    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'voice_part_id' => $soprano->id, 'status' => CandidateStatus::Registered]);
    // Alto: 1 pending (not counted) = 0.
    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'voice_part_id' => $alto->id, 'status' => CandidateStatus::Pending]);

    $component = Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version]);

    // Column headers (abbr values SOP, ALT, Registered) come before the
    // single row of values (1, 0, 1) in DOM order. The voice part table
    // uses abbr, not the full name (the full names still legitimately
    // appear elsewhere, in the "All voice parts" filter/enroll dropdowns,
    // so this doesn't assertDontSee them).
    $component->assertSeeInOrder(['SOP', 'ALT', 'Registered', '1', '0', '1']);
    $component->assertSeeInOrder(['Eligible', 'Pending', 'Registered', 'Total', '1', '1', '1', '3']);
});

test('search filters candidates by the linked student user\'s name', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    inviteRegistrationTeacher($teacher, $version);

    $wendel = Student::factory()->create();
    $wendel->user->update(['first_name' => 'Wendel', 'last_name' => 'Quoxbury']);
    $zoe = Student::factory()->create();
    $zoe->user->update(['first_name' => 'Zoe', 'last_name' => 'Adams']);

    // CandidateObserver::created() writes a candidate_status_history row
    // with user_id = Auth::id(), which is NOT NULL — needs an authenticated
    // user in place before the insert, not just at Livewire::actingAs() below.
    actingAs($teacher->user);

    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'student_id' => $wendel->id, 'program_name' => 'Wendel Quoxbury']);
    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'student_id' => $zoe->id, 'program_name' => 'Zoe Adams']);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->set('search', 'wendel')
        ->assertSee('Quoxbury, Wendel')
        ->assertDontSee('Adams, Zoe');
});

test('voicePartFilter shows only candidates with the selected voice part', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    inviteRegistrationTeacher($teacher, $version);

    $soprano = VoicePart::factory()->create(['name' => 'Soprano']);
    $alto = VoicePart::factory()->create(['name' => 'Alto']);

    $sopranoStudent = Student::factory()->create();
    $sopranoStudent->user->update(['first_name' => 'Sally', 'last_name' => 'Soprano']);
    $altoStudent = Student::factory()->create();
    $altoStudent->user->update(['first_name' => 'Alan', 'last_name' => 'Alto']);

    actingAs($teacher->user);

    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'student_id' => $sopranoStudent->id, 'voice_part_id' => $soprano->id]);
    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'student_id' => $altoStudent->id, 'voice_part_id' => $alto->id]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->set('voicePartFilter', (string) $alto->id)
        ->assertSee('Alto, Alan')
        ->assertDontSee('Soprano, Sally');
});

test('statusFilter shows only candidates with the selected status', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    inviteRegistrationTeacher($teacher, $version);

    $eligibleStudent = Student::factory()->create();
    $eligibleStudent->user->update(['first_name' => 'Ellie', 'last_name' => 'Eligible']);
    $registeredStudent = Student::factory()->create();
    $registeredStudent->user->update(['first_name' => 'Rita', 'last_name' => 'Registered']);

    actingAs($teacher->user);

    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'student_id' => $eligibleStudent->id, 'status' => CandidateStatus::Eligible]);
    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'student_id' => $registeredStudent->id, 'status' => CandidateStatus::Registered]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->set('statusFilter', CandidateStatus::Registered->value)
        ->assertSee('Registered, Rita')
        ->assertDontSee('Eligible, Ellie');
});

test('search, voicePartFilter, and statusFilter combine, and an empty result shows the no-match message', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    inviteRegistrationTeacher($teacher, $version);

    $soprano = VoicePart::factory()->create(['name' => 'Soprano']);
    $wendel = Student::factory()->create();
    $wendel->user->update(['first_name' => 'Wendel', 'last_name' => 'Quoxbury']);

    actingAs($teacher->user);

    Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'student_id' => $wendel->id,
        'voice_part_id' => $soprano->id,
        'status' => CandidateStatus::Eligible,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->set('search', 'wendel')
        ->set('voicePartFilter', (string) $soprano->id)
        ->set('statusFilter', CandidateStatus::Eligible->value)
        ->assertSee('Quoxbury, Wendel')
        ->set('statusFilter', CandidateStatus::Registered->value)
        ->assertDontSee('Quoxbury, Wendel')
        ->assertSee('No candidates match your search/filters.');
});

test('refreshStatus recalculates the candidate status from the checklist', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create(['emergency_contact_name' => true]);
    actingAs($teacher->user);
    inviteRegistrationTeacher($teacher, $version);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Eligible,
        'program_name' => 'A Candidate',
    ]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->call('refreshStatus', $candidate->id);

    // program_name is done but emergency contact is not, so the candidate
    // should move from eligible to pending — not stay eligible.
    expect($candidate->refresh()->status)->toBe(CandidateStatus::Pending);
});
