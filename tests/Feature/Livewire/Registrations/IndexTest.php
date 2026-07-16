<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\VersionInvitationStatus;
use App\Livewire\Registrations\Index;
use App\Models\Candidate;
use App\Models\County;
use App\Models\Event;
use App\Models\Organization;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionCounty;
use App\Models\VersionDate;
use App\Models\VersionInvitation;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeIndexTeacher(): Teacher
{
    $user = User::factory()->create();

    return Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
}

function attachIndexTeacherSchool(Teacher $teacher): School
{
    $school = School::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);

    return $school;
}

function makeIndexVersion(): Version
{
    $organization = Organization::factory()->create();
    $event = Event::factory()->create(['organization_id' => $organization->id]);

    return Version::factory()->create(['event_id' => $event->id]);
}

function openTeacherWindow(Version $version): void
{
    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => 'teacher',
        'start_at' => now()->subDay(),
        'end_at' => null,
    ]);
}

test('an eligible, uninvited teacher sees the open Version under Invitation Available, not Open for Registration', function () {
    $teacher = makeIndexTeacher();
    attachIndexTeacherSchool($teacher);
    $version = makeIndexVersion();
    openTeacherWindow($version);

    Livewire::actingAs($teacher->user)
        ->test(Index::class)
        ->assertSee($version->name)
        ->assertSee('Invitation Available')
        ->assertSee('Request Invitation')
        ->assertDontSee('Open for Registration')
        ->assertDontSee('Manage');
});

test('an ineligible teacher with no school does not see the open Version at all', function () {
    $teacher = makeIndexTeacher();
    // No active+verified school attached — fails the base eligibility gate.
    $version = makeIndexVersion();
    openTeacherWindow($version);

    Livewire::actingAs($teacher->user)
        ->test(Index::class)
        ->assertDontSee($version->name);
});

test('an invited teacher sees Open for Registration with Manage, not Invitation Available', function () {
    $teacher = makeIndexTeacher();
    attachIndexTeacherSchool($teacher);
    $version = makeIndexVersion();
    openTeacherWindow($version);

    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(Index::class)
        ->assertSee('Open for Registration')
        ->assertSee('Manage')
        ->assertDontSee('Invitation Available')
        ->assertDontSee('Request Invitation');
});

test('an invited teacher still sees Open/Manage even once their current eligibility no longer holds', function () {
    $teacher = makeIndexTeacher();
    $version = makeIndexVersion();
    openTeacherWindow($version);

    // County-restrict the Version, then attach the teacher to a school in a
    // different county with no organization membership — isEligible() is now
    // false even though the teacher was invited (and presumably eligible) at
    // some point in the past. The "open" bucket's rule is "open AND invited"
    // — the invitation itself is the standing that matters here, not the
    // pool computation, so a lapsed computed-eligibility doesn't remove
    // access to something the teacher is already invited to.
    $configuredCounty = County::factory()->create();
    $otherCounty = County::factory()->create();
    VersionCounty::create(['version_id' => $version->id, 'county_id' => $configuredCounty->id]);
    $school = School::factory()->create(['county_id' => $otherCounty->id]);
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);

    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(Index::class)
        ->assertSee($version->name)
        ->assertSee('Open for Registration')
        ->assertSee('Manage');
});

test('a teacher with existing candidates still sees the Version even after the window closes and eligibility no longer holds', function () {
    $teacher = makeIndexTeacher();
    $school = attachIndexTeacherSchool($teacher);
    $version = makeIndexVersion();
    // No open teacher window this time — window has closed.

    $voicePart = VoicePart::factory()->create();

    // CandidateObserver::created() writes a candidate_status_history row
    // with user_id = Auth::id(), which is NOT NULL — needs an authenticated
    // user in place before the insert, not just at Livewire::actingAs() below.
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

    Livewire::actingAs($teacher->user)
        ->test(Index::class)
        ->assertSee($version->name)
        ->assertSee('Active Candidates');
});

test('versions are sorted by descending senior_class_of, then ascending name', function () {
    $teacher = makeIndexTeacher();
    attachIndexTeacherSchool($teacher);

    $organization = Organization::factory()->create();
    $event = Event::factory()->create(['organization_id' => $organization->id]);

    $zeta2025 = Version::factory()->create(['event_id' => $event->id, 'senior_class_of' => 2025, 'name' => 'Zeta Version']);
    $alpha2026 = Version::factory()->create(['event_id' => $event->id, 'senior_class_of' => 2026, 'name' => 'Alpha Version']);
    $alpha2025 = Version::factory()->create(['event_id' => $event->id, 'senior_class_of' => 2025, 'name' => 'Alpha Version 2']);

    foreach ([$zeta2025, $alpha2026, $alpha2025] as $version) {
        openTeacherWindow($version);

        VersionInvitation::create([
            'version_id' => $version->id,
            'teacher_id' => $teacher->id,
            'status' => VersionInvitationStatus::Invited->value,
            'invited_at' => now(),
            'invited_by_user_id' => User::factory()->create()->id,
        ]);
    }

    // Expected order: 2026 first (highest senior_class_of), then within the
    // tied 2025 group, ascending by name.
    Livewire::actingAs($teacher->user)
        ->test(Index::class)
        ->assertSeeInOrder([$alpha2026->name, $alpha2025->name, $zeta2025->name]);
});

test('the empty-state callout shows when nothing is open or active', function () {
    $teacher = makeIndexTeacher();
    attachIndexTeacherSchool($teacher);

    Livewire::actingAs($teacher->user)
        ->test(Index::class)
        ->assertSee('No events are currently open for registration');
});
