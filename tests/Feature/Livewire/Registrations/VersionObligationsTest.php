<?php

declare(strict_types=1);

use App\Enums\VersionInvitationStatus;
use App\Enums\VersionObligationStatus;
use App\Livewire\Registrations\VersionObligations;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VersionObligation;
use App\Models\VersionObligationResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeObligationsTeacher(): Teacher
{
    $user = User::factory()->create();

    return Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
}

function inviteTeacherToVersion(Teacher $teacher, Version $version): VersionInvitation
{
    return VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);
}

function publishObligationFor(Version $version, string $body = '<p>Be excellent.</p>'): VersionObligation
{
    return VersionObligation::create([
        'version_id' => $version->id,
        'body' => $body,
        'status' => VersionObligationStatus::Published->value,
        'published_at' => now(),
        'published_by_user_id' => User::factory()->create()->id,
    ]);
}

test('mount aborts with 404 when the teacher has no invitation for this Version', function () {
    $teacher = makeObligationsTeacher();
    $version = Version::factory()->create();

    Livewire::actingAs($teacher->user)
        ->test(VersionObligations::class, ['version' => $version])
        ->assertStatus(404);
});

test('mount succeeds for an invited teacher', function () {
    $teacher = makeObligationsTeacher();
    $version = Version::factory()->create();
    inviteTeacherToVersion($teacher, $version);

    Livewire::actingAs($teacher->user)
        ->test(VersionObligations::class, ['version' => $version])
        ->assertOk();
});

test('a draft obligation is not shown to the teacher', function () {
    $teacher = makeObligationsTeacher();
    $version = Version::factory()->create();
    inviteTeacherToVersion($teacher, $version);

    VersionObligation::create([
        'version_id' => $version->id,
        'body' => '<p>Secret draft text.</p>',
        'status' => VersionObligationStatus::Draft->value,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(VersionObligations::class, ['version' => $version])
        ->assertDontSee('Secret draft text.')
        ->assertSee('Not yet published');
});

test('a published obligation is shown with merge fields resolved', function () {
    $teacher = makeObligationsTeacher();
    $version = Version::factory()->create(['short_name' => 'TDR27']);
    inviteTeacherToVersion($teacher, $version);
    publishObligationFor($version, '<p>Welcome to {{versionShortName}}.</p>');

    Livewire::actingAs($teacher->user)
        ->test(VersionObligations::class, ['version' => $version])
        ->assertSee('Welcome to TDR27');
});

test('accept creates a response, records the decision, and sets the invitation status to Obligated', function () {
    $teacher = makeObligationsTeacher();
    $version = Version::factory()->create();
    $invitation = inviteTeacherToVersion($teacher, $version);
    $obligation = publishObligationFor($version);

    Livewire::actingAs($teacher->user)
        ->test(VersionObligations::class, ['version' => $version])
        ->call('accept')
        ->assertSee('Accepted');

    $response = VersionObligationResponse::where('version_invitation_id', $invitation->id)->first();

    expect($response)->not->toBeNull();
    expect($response->version_obligation_id)->toBe($obligation->id);
    expect($response->getRawOriginal('decision'))->toBe('accepted');
    expect($invitation->fresh()->getRawOriginal('status'))->toBe('obligated');
});

test('reject creates a response and does not change the invitation status', function () {
    $teacher = makeObligationsTeacher();
    $version = Version::factory()->create();
    $invitation = inviteTeacherToVersion($teacher, $version);
    publishObligationFor($version);

    Livewire::actingAs($teacher->user)
        ->test(VersionObligations::class, ['version' => $version])
        ->call('reject')
        ->assertSee('Rejected');

    $response = VersionObligationResponse::where('version_invitation_id', $invitation->id)->first();

    expect($response->getRawOriginal('decision'))->toBe('rejected');
    expect($invitation->fresh()->getRawOriginal('status'))->toBe('invited');
});

test('toggling accept then reject then accept updates the same response row and ends Obligated', function () {
    $teacher = makeObligationsTeacher();
    $version = Version::factory()->create();
    $invitation = inviteTeacherToVersion($teacher, $version);
    publishObligationFor($version);

    $component = Livewire::actingAs($teacher->user)
        ->test(VersionObligations::class, ['version' => $version]);

    $component->call('accept');
    $component->call('reject');
    $component->call('accept');

    expect(VersionObligationResponse::where('version_invitation_id', $invitation->id)->count())->toBe(1);
    expect($invitation->fresh()->getRawOriginal('status'))->toBe('obligated');
});

test('accept records a frozen snapshot independent of later edits to the obligation body', function () {
    $teacher = makeObligationsTeacher();
    $version = Version::factory()->create();
    $invitation = inviteTeacherToVersion($teacher, $version);
    $obligation = publishObligationFor($version, '<p>Original text.</p>');

    Livewire::actingAs($teacher->user)
        ->test(VersionObligations::class, ['version' => $version])
        ->call('accept');

    $obligation->update(['body' => '<p>Changed after acceptance.</p>']);

    $response = VersionObligationResponse::where('version_invitation_id', $invitation->id)->first();

    expect($response->obligation_snapshot)->toContain('Original text.');
    expect($response->obligation_snapshot)->not->toContain('Changed after acceptance.');
});

test('accept aborts with 404 when there is no published obligation', function () {
    $teacher = makeObligationsTeacher();
    $version = Version::factory()->create();
    inviteTeacherToVersion($teacher, $version);

    Livewire::actingAs($teacher->user)
        ->test(VersionObligations::class, ['version' => $version])
        ->call('accept')
        ->assertStatus(404);
});
