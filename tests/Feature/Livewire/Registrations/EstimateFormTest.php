<?php

declare(strict_types=1);

use App\Enums\VersionObligationStatus;
use App\Livewire\Registrations\EstimateForm;
use App\Models\Candidate;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VersionObligation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeEstimateFormTeacher(): Teacher
{
    $user = User::factory()->create();

    return Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
}

function inviteEstimateFormTeacher(Teacher $teacher, Version $version): void
{
    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => 'invited',
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * A registered Candidate for $teacher at $school, on $version, with the
 * teacher's school_teacher pivot marked active+verified (required for the
 * school to appear on the Estimate Form at all — see
 * EstimateForm::schoolsWithCandidates()).
 */
function registerEstimateFormCandidate(Teacher $teacher, Version $version, School $school): Candidate
{
    if (! $teacher->schools()->where('schools.id', $school->id)->exists()) {
        $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    }

    return Candidate::factory()->registered()->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
    ]);
}

test('mount aborts with 403 for a teacher with no invitation on this Version', function () {
    $teacher = makeEstimateFormTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);

    Livewire::test(EstimateForm::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount redirects an invited teacher who has not yet responded to a published obligation', function () {
    $teacher = makeEstimateFormTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);
    inviteEstimateFormTeacher($teacher, $version);
    VersionObligation::create([
        'version_id' => $version->id,
        'body' => '<p>Be excellent.</p>',
        'status' => VersionObligationStatus::Published->value,
        'published_at' => now(),
        'published_by_user_id' => User::factory()->create()->id,
    ]);

    Livewire::test(EstimateForm::class, ['version' => $version])
        ->assertRedirect(route('registrations.obligations', $version));
});

test('an invited teacher with no registered candidates sees the empty-state message', function () {
    $teacher = makeEstimateFormTeacher();
    $version = Version::factory()->create(['name' => 'Fall Auditions']);
    actingAs($teacher->user);
    inviteEstimateFormTeacher($teacher, $version);

    Livewire::test(EstimateForm::class, ['version' => $version])
        ->assertSee('Fall Auditions')
        ->assertSee('no candidates registered for this Version yet');
});

test('a teacher with registered candidates at exactly one school sees an inline summary and a download link', function () {
    $teacher = makeEstimateFormTeacher();
    $version = Version::factory()->create(['name' => 'Fall Auditions']);
    $school = School::factory()->create(['name' => 'Chatham High School']);
    actingAs($teacher->user);
    inviteEstimateFormTeacher($teacher, $version);

    registerEstimateFormCandidate($teacher, $version, $school);
    registerEstimateFormCandidate($teacher, $version, $school);

    Livewire::test(EstimateForm::class, ['version' => $version])
        ->assertSee('Chatham High School')
        ->assertSee('2 registered candidate(s)')
        ->assertSee(route('registrations.estimate-form-pdf', [$version, $school]), false);
});

test('a teacher with registered candidates at two schools sees a picker, not an inline summary', function () {
    $teacher = makeEstimateFormTeacher();
    $version = Version::factory()->create();
    $schoolA = School::factory()->create(['name' => 'Chatham High School']);
    $schoolB = School::factory()->create(['name' => 'Morris Knolls High School']);
    actingAs($teacher->user);
    inviteEstimateFormTeacher($teacher, $version);

    registerEstimateFormCandidate($teacher, $version, $schoolA);
    registerEstimateFormCandidate($teacher, $version, $schoolB);

    Livewire::test(EstimateForm::class, ['version' => $version])
        ->assertSee('Chatham High School')
        ->assertSee('Morris Knolls High School')
        ->assertSee(route('registrations.estimate-form-pdf', [$version, $schoolA]), false)
        ->assertSee(route('registrations.estimate-form-pdf', [$version, $schoolB]), false);
});

test('a school with a registered candidate is excluded when the teacher is no longer active+verified there', function () {
    $teacher = makeEstimateFormTeacher();
    $version = Version::factory()->create();
    $school = School::factory()->create();
    actingAs($teacher->user);
    inviteEstimateFormTeacher($teacher, $version);

    $teacher->schools()->attach($school->id, ['is_active' => false, 'verified_at' => now()]);
    Candidate::factory()->registered()->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
    ]);

    Livewire::test(EstimateForm::class, ['version' => $version])
        ->assertSee('no candidates registered for this Version yet');
});
