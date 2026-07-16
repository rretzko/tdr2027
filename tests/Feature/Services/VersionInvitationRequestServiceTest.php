<?php

declare(strict_types=1);

use App\Enums\VersionInvitationRequestStatus;
use App\Enums\VersionInvitationStatus;
use App\Models\Event;
use App\Models\Organization;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VersionInvitationRequest;
use App\Services\VersionInvitationRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeRequestableTeacher(Version $version): Teacher
{
    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);

    return $teacher;
}

function makeRequestableVersion(): Version
{
    $organization = Organization::factory()->create();
    $event = Event::factory()->create(['organization_id' => $organization->id]);

    return Version::factory()->create(['event_id' => $event->id]);
}

test('canRequest is true for an eligible teacher with no invitation and no prior request', function () {
    $version = makeRequestableVersion();
    $teacher = makeRequestableTeacher($version);

    expect(app(VersionInvitationRequestService::class)->canRequest($version, $teacher))->toBeTrue();
});

test('canRequest is false once the teacher already has a version_invitations row', function () {
    $version = makeRequestableVersion();
    $teacher = makeRequestableTeacher($version);

    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    expect(app(VersionInvitationRequestService::class)->canRequest($version, $teacher))->toBeFalse();
});

test('canRequest is false while a request is pending', function () {
    $version = makeRequestableVersion();
    $teacher = makeRequestableTeacher($version);

    app(VersionInvitationRequestService::class)->request($version, $teacher);

    expect(app(VersionInvitationRequestService::class)->canRequest($version, $teacher))->toBeFalse();
});

test('canRequest is true again once a prior request was denied', function () {
    $version = makeRequestableVersion();
    $teacher = makeRequestableTeacher($version);

    VersionInvitationRequest::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationRequestStatus::Denied->value,
        'requested_at' => now()->subDay(),
        'decided_at' => now(),
        'decided_by_user_id' => User::factory()->create()->id,
    ]);

    expect(app(VersionInvitationRequestService::class)->canRequest($version, $teacher))->toBeTrue();
});

test('canRequest is false for a teacher outside the eligible pool', function () {
    $version = makeRequestableVersion();
    $teacher = Teacher::factory()->create();
    // No active+verified school attached — fails the base eligibility gate.

    expect(app(VersionInvitationRequestService::class)->canRequest($version, $teacher))->toBeFalse();
});

test('request creates a pending row', function () {
    $version = makeRequestableVersion();
    $teacher = makeRequestableTeacher($version);

    $request = app(VersionInvitationRequestService::class)->request($version, $teacher);

    expect($request->getRawOriginal('status'))->toBe(VersionInvitationRequestStatus::Pending->value)
        ->and($request->version_id)->toBe($version->id)
        ->and($request->teacher_id)->toBe($teacher->id)
        ->and($request->decided_at)->toBeNull();
});

test('request resets a denied row back to pending rather than creating a second row', function () {
    $version = makeRequestableVersion();
    $teacher = makeRequestableTeacher($version);

    $original = VersionInvitationRequest::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationRequestStatus::Denied->value,
        'requested_at' => now()->subDay(),
        'decided_at' => now()->subDay(),
        'decided_by_user_id' => User::factory()->create()->id,
    ]);

    $request = app(VersionInvitationRequestService::class)->request($version, $teacher);

    expect(VersionInvitationRequest::count())->toBe(1)
        ->and($request->id)->toBe($original->id)
        ->and($request->getRawOriginal('status'))->toBe(VersionInvitationRequestStatus::Pending->value)
        ->and($request->decided_at)->toBeNull()
        ->and($request->decided_by_user_id)->toBeNull();
});

test('request throws when the teacher is not allowed to request', function () {
    $version = makeRequestableVersion();
    $teacher = Teacher::factory()->create();

    app(VersionInvitationRequestService::class)->request($version, $teacher);
})->throws(RuntimeException::class);

test('approve marks the request approved and creates a version_invitations row', function () {
    $version = makeRequestableVersion();
    $teacher = makeRequestableTeacher($version);
    $eventManager = User::factory()->create();

    $service = app(VersionInvitationRequestService::class);
    $request = $service->request($version, $teacher);

    $invitation = $service->approve($request, $eventManager);

    expect($request->fresh()->getRawOriginal('status'))->toBe(VersionInvitationRequestStatus::Approved->value)
        ->and($request->fresh()->decided_by_user_id)->toBe($eventManager->id)
        ->and($invitation->getRawOriginal('status'))->toBe(VersionInvitationStatus::Invited->value)
        ->and($invitation->invited_by_user_id)->toBe($eventManager->id)
        ->and($invitation->version_id)->toBe($version->id)
        ->and($invitation->teacher_id)->toBe($teacher->id);
});

test('deny marks the request denied without creating a version_invitations row', function () {
    $version = makeRequestableVersion();
    $teacher = makeRequestableTeacher($version);
    $eventManager = User::factory()->create();

    $service = app(VersionInvitationRequestService::class);
    $request = $service->request($version, $teacher);

    $service->deny($request, $eventManager);

    expect($request->fresh()->getRawOriginal('status'))->toBe(VersionInvitationRequestStatus::Denied->value)
        ->and($request->fresh()->decided_by_user_id)->toBe($eventManager->id)
        ->and(VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->exists())->toBeFalse();
});

test('approve throws when the request was already decided', function () {
    $version = makeRequestableVersion();
    $teacher = makeRequestableTeacher($version);
    $eventManager = User::factory()->create();

    $service = app(VersionInvitationRequestService::class);
    $request = $service->request($version, $teacher);
    $service->approve($request, $eventManager);

    $service->approve($request->fresh(), $eventManager);
})->throws(RuntimeException::class);

test('deny throws when the request was already decided', function () {
    $version = makeRequestableVersion();
    $teacher = makeRequestableTeacher($version);
    $eventManager = User::factory()->create();

    $service = app(VersionInvitationRequestService::class);
    $request = $service->request($version, $teacher);
    $service->deny($request, $eventManager);

    $service->deny($request->fresh(), $eventManager);
})->throws(RuntimeException::class);
