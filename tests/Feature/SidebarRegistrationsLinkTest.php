<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\VersionInvitationStatus;
use App\Models\Candidate;
use App\Models\Event;
use App\Models\Organization;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionInvitation;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeSidebarTeacher(): Teacher
{
    $user = User::factory()->create();
    $user->markEmailAsVerified();

    return Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
}

function makeSidebarVersion(): Version
{
    $organization = Organization::factory()->create();
    $event = Event::factory()->create(['organization_id' => $organization->id]);

    return Version::factory()->create(['event_id' => $event->id]);
}

test('the Registrations link is hidden for a teacher with an active school but no registration access', function () {
    $teacher = makeSidebarTeacher();
    $school = School::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    // No open+eligible Version, no invitation, no candidates.

    actingAs($teacher->user)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('href="'.route('registrations.index').'"', false);
});

test('the Registrations link appears when the teacher is eligible for a currently open Version', function () {
    $teacher = makeSidebarTeacher();
    $school = School::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    $version = makeSidebarVersion();

    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => 'teacher',
        'start_at' => now()->subDay(),
        'end_at' => null,
    ]);

    actingAs($teacher->user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('href="'.route('registrations.index').'"', false);
});

test('the Registrations link appears once the teacher has an invitation, even with no open window', function () {
    $teacher = makeSidebarTeacher();
    $school = School::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    $version = makeSidebarVersion();

    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    actingAs($teacher->user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('href="'.route('registrations.index').'"', false);
});

test('the Registrations link appears for a teacher with an existing active candidate', function () {
    $teacher = makeSidebarTeacher();
    $school = School::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    $version = makeSidebarVersion();
    $voicePart = VoicePart::factory()->create();

    // CandidateObserver::created() writes a candidate_status_history row
    // with user_id = Auth::id(), which is NOT NULL — needs an authenticated
    // user in place before the insert, not just at request time below.
    actingAs($teacher->user);

    Candidate::create([
        'student_id' => Student::factory()->create()->id,
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'voice_part_id' => $voicePart->id,
        'status' => CandidateStatus::Registered->value,
        'program_name' => 'Test Candidate',
        'emergency_contact_id' => null,
    ]);

    actingAs($teacher->user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('href="'.route('registrations.index').'"', false);
});

test('the Registrations link stays hidden without an active school even if an open Version exists', function () {
    $teacher = makeSidebarTeacher();
    // No school attached at all.
    $version = makeSidebarVersion();

    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => 'teacher',
        'start_at' => now()->subDay(),
        'end_at' => null,
    ]);

    actingAs($teacher->user)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('href="'.route('registrations.index').'"', false);
});
