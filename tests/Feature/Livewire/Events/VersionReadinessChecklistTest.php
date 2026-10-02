<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\EventStatus;
use App\Enums\PitchFileVisibility;
use App\Enums\ReadinessStatus;
use App\Enums\ScoreOrder;
use App\Enums\UploadType;
use App\Livewire\Events\Show;
use App\Livewire\Events\VersionEdit;
use App\Livewire\Events\VersionReadinessChecklist;
use App\Models\User;
use App\Models\Version;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Submits the General tab with every required field set to the Version's
 * current values, plus the requested status.
 */
function readinessSaveGeneralWithStatus(User $user, Version $version, EventStatus $status): Testable
{
    return Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('status', $status->value)
        ->set('application_type', ApplicationType::Pdf->value)
        ->set('audition_type', AuditionType::Remote->value)
        ->set('upload_type', UploadType::None->value)
        ->set('score_order', ScoreOrder::Asc->value)
        ->set('pitch_file_visibility', PitchFileVisibility::Both->value)
        ->call('saveGeneral');
}

test('the checklist is forbidden to a user with no role on the Version', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(VersionReadinessChecklist::class, ['version' => readinessVersion()])
        ->assertStatus(403);
});

test('the checklist is available to an Event Manager, a Registration Manager, and the Founder', function (string $who) {
    $version = readinessVersion();
    $user = $who === 'founder' ? makeFounder() : User::factory()->create();

    if ($who !== 'founder') {
        grantVersionRole($user, $version, $who);
    }

    Livewire::actingAs($user)
        ->test(VersionReadinessChecklist::class, ['version' => $version])
        ->assertOk()
        ->assertSee('Setup checklist')
        ->assertSee('Who runs registration?');
})->with(['Event Manager', 'Registration Manager', 'founder']);

test('the checklist hides not-applicable items until asked to show them', function () {
    $version = readinessVersion(['upload_type' => UploadType::None]);

    Livewire::actingAs(makeFounder())
        ->test(VersionReadinessChecklist::class, ['version' => $version])
        ->assertDontSee('What recordings must each student submit?')
        ->set('showNotApplicable', true)
        ->assertSee('What recordings must each student submit?');
});

test('Looks right acknowledges a reviewable item and toasts', function () {
    $version = readinessVersion();
    $user = User::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionReadinessChecklist::class, ['version' => $version])
        ->call('acknowledge', 'version.caps')
        ->assertDispatched('toast-show');

    expect(readinessStatus($version, 'version.caps'))->toBe(ReadinessStatus::Done);
});

test('Looks right refuses a required item that has no data yet', function () {
    $version = readinessVersion();

    Livewire::actingAs(makeFounder())
        ->test(VersionReadinessChecklist::class, ['version' => $version])
        ->call('acknowledge', 'version.dates.teacher');

    expect($version->readinessReviews()->count())->toBe(0);
});

test('an Event Manager cannot activate an unconfigured Version, but the other General fields still save', function () {
    $version = readinessVersion(['name' => 'Old Name']);
    $user = User::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    $component = Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('name', 'New Name')
        ->set('status', EventStatus::Active->value)
        ->call('saveGeneral')
        ->assertHasErrors('status')
        ->assertSet('status', EventStatus::Sandbox->value);

    expect($component->get('activation_blockers'))->not->toBeEmpty();
    expect(collect($component->get('activation_blockers'))->pluck('question'))->toContain('Who runs registration?');

    $version->refresh();
    expect($version->name)->toBe('New Name')
        ->and($version->status)->toBe(EventStatus::Sandbox);
});

test('an Event Manager can activate a Version whose pre-registration setup is complete', function () {
    $version = readinessConfiguredVersion();
    $user = User::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    readinessSaveGeneralWithStatus($user, $version, EventStatus::Active)
        ->assertHasNoErrors();

    expect($version->fresh()->status)->toBe(EventStatus::Active);
});

test('the Founder can activate an unconfigured Version', function () {
    $version = readinessVersion();

    readinessSaveGeneralWithStatus(makeFounder(), $version, EventStatus::Active)
        ->assertHasNoErrors();

    expect($version->fresh()->status)->toBe(EventStatus::Active);
});

test('the gate only applies to Sandbox to Active, not other status changes', function () {
    $version = readinessVersion();
    $user = User::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    readinessSaveGeneralWithStatus($user, $version, EventStatus::Inactive)
        ->assertHasNoErrors();

    expect($version->fresh()->status)->toBe(EventStatus::Inactive);
});

test('saving a VersionEdit tab marks that tab\'s readiness items as reviewed', function () {
    $version = readinessVersion();
    $user = User::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    expect(readinessStatus($version, 'version.requirements.student_fields'))->toBe(ReadinessStatus::NeedsReview);

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->call('saveRequirements')
        ->assertHasNoErrors();

    expect(readinessStatus($version, 'version.requirements.student_fields'))->toBe(ReadinessStatus::Done);
});

test('VersionEdit shows the setup progress card', function () {
    $version = readinessVersion();

    Livewire::actingAs(makeFounder())
        ->test(VersionEdit::class, ['version' => $version])
        ->assertSee('Setup progress')
        ->assertSee(route('events.versions.readiness', $version));
});

test('the Events Show page shows a setup bar for open Versions only', function () {
    $open = readinessVersion();
    $closed = Version::factory()->create(['event_id' => $open->event_id, 'status' => EventStatus::Closed]);

    Livewire::actingAs(makeFounder())
        ->test(Show::class, ['event' => $open->event])
        ->assertSee(route('events.versions.readiness', $open))
        ->assertDontSee(route('events.versions.readiness', $closed));
});

test('the checklist offers a tour with anchors for every step it explains', function () {
    $version = readinessVersion();

    Livewire::actingAs(makeFounder())
        ->test(VersionReadinessChecklist::class, ['version' => $version])
        ->assertSee('Take a tour')
        ->assertSeeHtml('id="tour-setup-progress"')
        ->assertSeeHtml('id="tour-phase-before_invitations"')
        ->assertSeeHtml('id="tour-col-status"')
        ->assertSeeHtml('id="tour-col-decision"')
        ->assertSeeHtml('id="tour-col-current"')
        ->assertSeeHtml('id="tour-ack-desktop"')
        ->assertSeeHtml('id="tour-open-desktop"')
        ->assertSeeHtml('id="tour-status-legend"');
});

test('the tour auto-starts until dismissed, and dismissing records it', function () {
    $user = User::factory()->create();
    $version = readinessVersion();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionReadinessChecklist::class, ['version' => $version])
        ->assertSeeHtml('data-auto-start="1"')
        ->call('dismissOrientation');

    expect($user->fresh()->dismissed_readiness_orientation_at)->not->toBeNull();

    Livewire::actingAs($user->fresh())
        ->test(VersionReadinessChecklist::class, ['version' => $version])
        ->assertSeeHtml('data-auto-start="0"');
});
