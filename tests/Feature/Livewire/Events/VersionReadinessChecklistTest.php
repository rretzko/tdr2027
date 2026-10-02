<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\EventStatus;
use App\Enums\PitchFileVisibility;
use App\Enums\ReadinessEditor;
use App\Enums\ReadinessReviewState;
use App\Enums\ReadinessStatus;
use App\Enums\ScoreOrder;
use App\Enums\UploadType;
use App\Livewire\Events\Show;
use App\Livewire\Events\VersionCoRegistrationManagers;
use App\Livewire\Events\VersionEdit;
use App\Livewire\Events\VersionInvitations;
use App\Livewire\Events\VersionPitchFiles;
use App\Livewire\Events\VersionReadinessChecklist;
use App\Livewire\Events\VersionRooms;
use App\Livewire\Events\VersionScoringRubric;
use App\Models\ScoreCategory;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionPitchFile;
use App\Models\VersionReadinessReview;
use App\Models\VersionRoom;
use App\Models\VoicePart;
use App\Services\Readiness\ReadinessCatalog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

test('every readiness item\'s editor matches the gate on the page its Open link leads to', function () {
    $version = readinessVersion();
    $expected = [
        'events.show' => ReadinessEditor::EventManager,
        'events.versions.edit' => ReadinessEditor::EventManager,
        'events.versions.invitations' => ReadinessEditor::EventManager,
        'events.versions.pitch-files' => ReadinessEditor::EventManager,
        'events.versions.rooms' => ReadinessEditor::AuditionEnvironment,
        'events.versions.scoring-rubric' => ReadinessEditor::AuditionEnvironment,
        'events.versions.co-registration-managers' => ReadinessEditor::RegistrationManager,
    ];

    foreach (ReadinessCatalog::items() as $item) {
        $route = app('router')->getRoutes()->match(Request::create($item->urlFor($version)))->getName();

        expect(array_key_exists($route, $expected))->toBeTrue("{$item->key} links to unmapped route {$route}");
        expect($item->editor)->toBe($expected[$route], "{$item->key} ({$route})");
    }
});

test('a Registration Manager gets a lock and an explanation, not a 403, for Event-Manager-only settings', function () {
    $version = readinessVersion();
    $eventManager = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Conductor']);
    grantVersionRole($eventManager, $version, 'Event Manager');
    $registrationManager = User::factory()->create();
    grantVersionRole($registrationManager, $version, 'Registration Manager');

    Livewire::actingAs($registrationManager)
        ->test(VersionReadinessChecklist::class, ['version' => $version])
        // Rooms are editable by a Registration Manager — still a real link.
        ->assertSeeHtml('href="'.route('events.versions.rooms', $version).'"')
        // Configure is not — no link to it at all.
        ->assertDontSeeHtml('href="'.route('events.versions.edit', ['version' => $version, 'tab' => 'fees']).'"')
        ->call('explainLocked', 'version.fees')
        ->assertSet('lockedKey', 'version.fees')
        ->assertSee('Ask an Event Manager')
        ->assertSee($eventManager->email);
});

test('an Event Manager sees real links for every item', function () {
    $version = readinessVersion();
    $user = User::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionReadinessChecklist::class, ['version' => $version])
        ->assertSeeHtml('href="'.route('events.versions.edit', ['version' => $version, 'tab' => 'fees']).'"')
        ->assertDontSeeHtml('explainLocked(');
});

test('a Registration Manager cannot confirm an Event-Manager-only item, but can confirm one they own', function () {
    $version = readinessVersion();
    $user = User::factory()->create();
    grantVersionRole($user, $version, 'Registration Manager');

    Livewire::actingAs($user)
        ->test(VersionReadinessChecklist::class, ['version' => $version])
        ->call('acknowledge', 'version.caps')
        ->assertSet('lockedKey', 'version.caps')
        ->call('acknowledge', 'version.roles.co_registration');

    expect(readinessStatus($version, 'version.caps'))->toBe(ReadinessStatus::NeedsReview)
        ->and(readinessStatus($version, 'version.roles.co_registration'))->toBe(ReadinessStatus::Done);
});

/**
 * Flags $keys as ReviewRequired (as cloning would), runs $action, and
 * returns each key's resulting review state, sorted by key.
 *
 * @param  list<string>  $keys
 * @return array<string, ?string>
 */
function readinessReviewStatesAfter(Version $version, array $keys, Closure $action): array
{
    foreach ($keys as $key) {
        VersionReadinessReview::create(['version_id' => $version->id, 'item_key' => $key, 'state' => ReadinessReviewState::ReviewRequired]);
    }

    $action();

    return VersionReadinessReview::where('version_id', $version->id)
        ->whereIn('item_key', $keys)
        ->get()
        ->mapWithKeys(fn (VersionReadinessReview $r): array => [$r->item_key => $r->getRawOriginal('state')])
        ->sortKeys()
        ->all();
}

test('changing rooms on the Rooms page counts as reviewing rooms and judges', function () {
    $version = readinessVersion();
    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Room A', 'order_by' => 1]);

    $states = readinessReviewStatesAfter($version, ['version.rooms', 'version.room_judges'], fn () => Livewire::actingAs(makeFounder())
        ->test(VersionRooms::class, ['version' => $version])
        ->call('remove', $room->id));

    expect($states)->toBe(['version.room_judges' => 'acknowledged', 'version.rooms' => 'acknowledged']);
});

test('changing the scoring rubric counts as reviewing it', function () {
    $version = readinessVersion();
    $category = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => $version->id, 'description' => 'Tone', 'order_by' => 1]);

    $states = readinessReviewStatesAfter($version, ['version.rubric'], fn () => Livewire::actingAs(makeFounder())
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->call('removeCategory', $category->id));

    expect($states)->toBe(['version.rubric' => 'acknowledged']);
});

test('changing pitch files counts as reviewing them', function () {
    Storage::fake('s3');
    $version = readinessVersion();
    $file = VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => VoicePart::factory()->create()->id, 'name' => 'Warm-up', 'url' => 'pitch/warmup.mp3', 'order_by' => 1]);

    $states = readinessReviewStatesAfter($version, ['version.pitch_files'], fn () => Livewire::actingAs(makeFounder())
        ->test(VersionPitchFiles::class, ['version' => $version])
        ->call('remove', $file->id));

    expect($states)->toBe(['version.pitch_files' => 'acknowledged']);
});

test('inviting a teacher counts as reviewing invitations', function () {
    $version = readinessVersion();
    $teacher = Teacher::factory()->create();

    $states = readinessReviewStatesAfter($version, ['version.invitations'], fn () => Livewire::actingAs(makeFounder())
        ->test(VersionInvitations::class, ['version' => $version])
        ->call('toggle', $teacher->id));

    expect($states)->toBe(['version.invitations' => 'acknowledged']);
});

test('changing Co-Registration Managers counts as reviewing them', function () {
    $version = readinessVersion();
    $coManager = User::factory()->create();
    grantVersionRole($coManager, $version, 'Co-Registration Manager');

    $states = readinessReviewStatesAfter($version, ['version.roles.co_registration'], fn () => Livewire::actingAs(makeFounder())
        ->test(VersionCoRegistrationManagers::class, ['version' => $version])
        ->call('remove', $coManager->id));

    expect($states)->toBe(['version.roles.co_registration' => 'acknowledged']);
});

test('every reviewable item outside VersionEdit belongs to a page section', function () {
    $sectionless = collect(ReadinessCatalog::items())
        ->filter(fn ($item) => $item->section === null && ($item->yearSensitive || $item->acknowledgeable))
        ->reject(fn ($item) => str_starts_with($item->key, 'event.'))
        ->pluck('key')
        ->all();

    expect($sectionless)->toBe([]);
});

test('Configure → Requirements saves whether teachers mail materials, and the checklist follows it', function () {
    $version = readinessVersion(['application_type' => ApplicationType::EApplication, 'mail_required' => false]);
    $user = User::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    expect(readinessStatus($version, 'version.roles.mail_to'))->toBe(ReadinessStatus::NotApplicable);

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->assertSet('mail_required', false)
        ->set('mail_required', true)
        ->call('saveRequirements')
        ->assertHasNoErrors();

    expect($version->fresh()->mail_required)->toBeTrue()
        ->and(readinessStatus($version, 'version.roles.mail_to'))->toBe(ReadinessStatus::NotStarted)
        ->and(readinessStatus($version, 'version.dates.postmark_deadline'))->toBe(ReadinessStatus::NotStarted);
});
