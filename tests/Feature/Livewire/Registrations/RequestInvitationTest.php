<?php

declare(strict_types=1);

use App\Enums\VersionInvitationRequestStatus;
use App\Enums\VersionInvitationStatus;
use App\Livewire\Registrations\RequestInvitation;
use App\Mail\VersionInvitationRequestSubmittedMail;
use App\Models\Event;
use App\Models\Organization;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VersionInvitationRequest;
use App\Services\VersionRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeRequestPageTeacher(): Teacher
{
    $user = User::factory()->create();

    return Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
}

function makeRequestPageVersion(): Version
{
    $organization = Organization::factory()->create();
    $event = Event::factory()->create(['organization_id' => $organization->id]);

    return Version::factory()->create(['event_id' => $event->id]);
}

function attachRequestPageSchool(Teacher $teacher): void
{
    $school = School::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
}

function makeRequestPageEventManager(Version $version): User
{
    $eventManager = User::factory()->create();
    Teacher::factory()->create(['user_id' => $eventManager->id, 'onboarding_completed_at' => now()]);
    app(VersionRoleService::class)->withVersion($version, fn () => $eventManager->assignRole('Event Manager'));

    return $eventManager;
}

test('mount aborts with 403 for a teacher outside the eligible pool', function () {
    $teacher = makeRequestPageTeacher();
    $version = makeRequestPageVersion();
    // No active+verified school attached — fails the base eligibility gate.

    Livewire::actingAs($teacher->user)
        ->test(RequestInvitation::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount redirects to the Registration page when the teacher is already invited', function () {
    $teacher = makeRequestPageTeacher();
    $version = makeRequestPageVersion();
    attachRequestPageSchool($teacher);

    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(RequestInvitation::class, ['version' => $version])
        ->assertRedirect(route('registrations.version', $version));
});

test('mount succeeds for an eligible, uninvited teacher', function () {
    $teacher = makeRequestPageTeacher();
    $version = makeRequestPageVersion();
    attachRequestPageSchool($teacher);

    Livewire::actingAs($teacher->user)
        ->test(RequestInvitation::class, ['version' => $version])
        ->assertOk()
        ->assertSee('Request Invitation');
});

test('request creates a pending row and notifies each Event Manager', function () {
    Mail::fake();

    $teacher = makeRequestPageTeacher();
    $version = makeRequestPageVersion();
    attachRequestPageSchool($teacher);
    $eventManager = makeRequestPageEventManager($version);

    Livewire::actingAs($teacher->user)
        ->test(RequestInvitation::class, ['version' => $version])
        ->call('request')
        ->assertSee('Request Sent');

    $request = VersionInvitationRequest::where('version_id', $version->id)->where('teacher_id', $teacher->id)->first();

    expect($request)->not->toBeNull();
    expect($request->getRawOriginal('status'))->toBe(VersionInvitationRequestStatus::Pending->value);

    Mail::assertSent(VersionInvitationRequestSubmittedMail::class, fn ($mail) => $mail->hasTo($eventManager->email));
});

test('request button is disabled while a request is pending', function () {
    $teacher = makeRequestPageTeacher();
    $version = makeRequestPageVersion();
    attachRequestPageSchool($teacher);

    VersionInvitationRequest::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationRequestStatus::Pending->value,
        'requested_at' => now(),
    ]);

    Livewire::actingAs($teacher->user)
        ->test(RequestInvitation::class, ['version' => $version])
        ->assertSee('Request Sent')
        ->assertSeeHtml('disabled');
});

test('request is allowed again after a prior denial, and the view surfaces the denied state', function () {
    Mail::fake();

    $teacher = makeRequestPageTeacher();
    $version = makeRequestPageVersion();
    attachRequestPageSchool($teacher);
    makeRequestPageEventManager($version);

    VersionInvitationRequest::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationRequestStatus::Denied->value,
        'requested_at' => now()->subDay(),
        'decided_at' => now(),
        'decided_by_user_id' => User::factory()->create()->id,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(RequestInvitation::class, ['version' => $version])
        ->assertSee('Denied')
        ->call('request')
        ->assertSee('Request Sent');

    $request = VersionInvitationRequest::where('version_id', $version->id)->where('teacher_id', $teacher->id)->first();
    expect($request->getRawOriginal('status'))->toBe(VersionInvitationRequestStatus::Pending->value);
});
