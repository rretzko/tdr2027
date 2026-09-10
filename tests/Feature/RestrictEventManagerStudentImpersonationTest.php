<?php

declare(strict_types=1);

use App\Models\Candidate;
use App\Models\Event;
use App\Models\Organization;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeEventManagerPreviewVersion(): Version
{
    $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    return Version::factory()->create(['event_id' => $event->id]);
}

function eventManagerPreviewSession(int $managerId, int $versionId, int $candidateId): array
{
    return [
        'impersonator_id' => $managerId,
        'impersonation_scope' => 'event_manager_student_preview',
        'impersonation_version_id' => $versionId,
        'impersonation_candidate_id' => $candidateId,
    ];
}

test('an Event-Manager-student-preview session can reach the shared sfdi routes', function () {
    $manager = User::factory()->create();
    $version = makeEventManagerPreviewVersion();

    $studentUser = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $studentUser->id]);
    actingAs($studentUser);
    $candidate = Candidate::factory()->create(['student_id' => $student->id, 'version_id' => $version->id]);

    actingAs($studentUser)
        ->withSession(eventManagerPreviewSession($manager->id, $version->id, $candidate->id))
        ->get(route('sfdi.student-details'))
        ->assertOk();
});

test('an Event-Manager-student-preview session cannot reach the teacher/Founder/settings surface', function () {
    $manager = User::factory()->create();
    $version = makeEventManagerPreviewVersion();

    $studentUser = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $studentUser->id]);
    actingAs($studentUser);
    $candidate = Candidate::factory()->create(['student_id' => $student->id, 'version_id' => $version->id]);

    actingAs($studentUser)
        ->withSession(eventManagerPreviewSession($manager->id, $version->id, $candidate->id))
        ->get(route('settings.profile'))
        ->assertStatus(403);

    actingAs($studentUser)
        ->withSession(eventManagerPreviewSession($manager->id, $version->id, $candidate->id))
        ->get(route('registrations.index'))
        ->assertStatus(403);
});

test('an Event-Manager-student-preview session can reach its own locked candidate\'s application page', function () {
    $manager = User::factory()->create();
    $version = makeEventManagerPreviewVersion();

    $studentUser = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $studentUser->id]);
    $school = School::factory()->create();
    $student->schools()->attach($school->id, ['is_active' => true, 'class_of' => $school->senior_year + 1]);
    actingAs($studentUser);
    $candidate = Candidate::factory()->create(['student_id' => $student->id, 'version_id' => $version->id, 'school_id' => $school->id]);

    actingAs($studentUser)
        ->withSession(eventManagerPreviewSession($manager->id, $version->id, $candidate->id))
        ->get(route('sfdi.events.candidate', $candidate))
        ->assertOk();
});

test('an Event-Manager-student-preview session cannot reach a different candidate\'s application page', function () {
    $manager = User::factory()->create();
    $version = makeEventManagerPreviewVersion();

    $studentUser = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $studentUser->id]);
    actingAs($studentUser);
    $lockedCandidate = Candidate::factory()->create(['student_id' => $student->id, 'version_id' => $version->id]);
    $otherCandidate = Candidate::factory()->create(['version_id' => $version->id]);

    actingAs($studentUser)
        ->withSession(eventManagerPreviewSession($manager->id, $version->id, $lockedCandidate->id))
        ->get(route('sfdi.events.candidate', $otherCandidate))
        ->assertStatus(403);
});

test('a Founder-impersonated session (no impersonation_scope) is unaffected by the new restriction', function () {
    $founder = makeFounder();
    $studentUser = User::factory()->create();
    Student::factory()->create(['user_id' => $studentUser->id]);

    actingAs($studentUser)
        ->withSession(['impersonator_id' => $founder->id])
        ->get(route('settings.profile'))
        ->assertOk();
});

test('stopping an Event-Manager-student-preview session returns to the Web Registration screen', function () {
    $manager = User::factory()->create();
    $version = makeEventManagerPreviewVersion();
    grantVersionRole($manager, $version, 'Web Registration Manager');

    $studentUser = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $studentUser->id]);
    actingAs($studentUser);
    $candidate = Candidate::factory()->create(['student_id' => $student->id, 'version_id' => $version->id]);

    actingAs($studentUser)
        ->withSession(eventManagerPreviewSession($manager->id, $version->id, $candidate->id))
        ->post(route('founder.stop-impersonating'))
        ->assertRedirect(route('events.versions.web-registration', $version));

    expect(auth()->id())->toBe($manager->id);
    expect(session()->has('impersonator_id'))->toBeFalse();
    expect(session()->has('impersonation_scope'))->toBeFalse();
    expect(session()->has('impersonation_version_id'))->toBeFalse();
    expect(session()->has('impersonation_candidate_id'))->toBeFalse();
});
