<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\PaymentEnvironment;
use App\Enums\ReadinessPhase;
use App\Enums\ReadinessReviewState;
use App\Enums\ReadinessStatus;
use App\Enums\UploadType;
use App\Enums\VersionDateType;
use App\Enums\VersionInvitationStatus;
use App\Models\EventEpaymentConfig;
use App\Models\RoomJudge;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionEpaymentConfig;
use App\Models\VersionInvitation;
use App\Models\VersionReadinessReview;
use App\Models\VersionRoom;
use App\Services\Readiness\ReadinessCatalog;
use App\Services\Readiness\VersionReadiness;
use App\Services\VersionCloningService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Oct 2026 → this school year's seniors are the class of 2027.
    Carbon::setTestNow('2026-10-02 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('catalog item keys are unique', function () {
    $keys = collect(ReadinessCatalog::items())->pluck('key');

    expect($keys->duplicates())->toBeEmpty();
});

test('every Version column is accounted for by a readiness item or explicitly ignored', function () {
    // Lifecycle/system columns — not configuration decisions.
    $ignored = ['event_id', 'status', 'results_released_at'];

    $covered = collect(ReadinessCatalog::items())
        ->flatMap(fn ($item) => $item->covers)
        ->filter(fn (string $field): bool => str_starts_with($field, 'versions.'))
        ->map(fn (string $field): string => substr($field, strlen('versions.')));

    $missing = collect((new Version)->getFillable())
        ->reject(fn (string $column): bool => in_array($column, $ignored, true) || $covered->contains($column))
        ->values();

    expect($missing->all())->toBe([], 'Add a ReadinessCatalog item (or extend one\'s covers) for: '.$missing->implode(', '));
});

test('a brand-new Version has its required items not started and its default-backed items needing review', function () {
    $version = readinessVersion();

    expect(readinessStatus($version, 'event.ensembles'))->toBe(ReadinessStatus::NotStarted)
        ->and(readinessStatus($version, 'version.dates.teacher'))->toBe(ReadinessStatus::NotStarted)
        ->and(readinessStatus($version, 'version.application'))->toBe(ReadinessStatus::NotStarted)
        ->and(readinessStatus($version, 'version.roles.registration_manager'))->toBe(ReadinessStatus::NotStarted)
        ->and(readinessStatus($version, 'version.requirements.student_fields'))->toBe(ReadinessStatus::NeedsReview)
        ->and(readinessStatus($version, 'version.audition_format'))->toBe(ReadinessStatus::NeedsReview)
        ->and(readinessStatus($version, 'version.identity'))->toBe(ReadinessStatus::Done);
});

test('items that do not apply to this event are marked not applicable', function () {
    $version = readinessVersion([
        'application_type' => ApplicationType::EApplication,
        'upload_type' => UploadType::None,
    ]);

    expect(readinessStatus($version, 'version.dates.postmark_deadline'))->toBe(ReadinessStatus::NotApplicable)
        ->and(readinessStatus($version, 'version.upload_files'))->toBe(ReadinessStatus::NotApplicable)
        ->and(readinessStatus($version, 'event.epayment.credentials'))->toBe(ReadinessStatus::NotApplicable)
        ->and(readinessStatus($version, 'version.room_judges'))->toBe(ReadinessStatus::NotApplicable)
        ->and(readinessStatus($version, 'version.roles.mail_to'))->toBe(ReadinessStatus::NotApplicable)
        ->and(readinessStatus($version, 'version.ensemble_order'))->toBe(ReadinessStatus::NotApplicable);
});

test('turning on online payments makes the vendor credential item required', function () {
    $version = readinessVersion();
    VersionEpaymentConfig::create(['version_id' => $version->id, 'epayment_student' => true, 'epayment_teacher' => false]);

    expect(readinessStatus($version, 'event.epayment.credentials'))->toBe(ReadinessStatus::NotStarted);
});

test('postmark deadline only applies to PDF applications', function () {
    $version = readinessVersion(['application_type' => ApplicationType::Pdf]);

    expect(readinessStatus($version, 'version.dates.postmark_deadline'))->toBe(ReadinessStatus::NotStarted);
});

test('a date with a required end is in progress until the end is set', function () {
    $version = readinessVersion();
    readinessSetDate($version, VersionDateType::Candidate, withEnd: false);
    readinessSetDate($version, VersionDateType::Teacher, withEnd: false);

    expect(readinessStatus($version, 'version.dates.candidate'))->toBe(ReadinessStatus::InProgress)
        ->and(readinessStatus($version, 'version.dates.teacher'))->toBe(ReadinessStatus::Done);
});

test('a stale graduating class is flagged as in progress with an explanation', function () {
    $version = readinessVersion(['senior_class_of' => 2026]);

    $result = app(VersionReadiness::class)->evaluate($version)->get('version.identity');

    expect($result->status)->toBe(ReadinessStatus::InProgress)
        ->and($result->detail)->toContain('class of 2027');
});

test('acknowledging a default-backed item marks it done', function () {
    $version = readinessVersion();
    $user = User::factory()->create();

    app(VersionReadiness::class)->acknowledge($version, 'version.caps', $user);

    expect(readinessStatus($version, 'version.caps'))->toBe(ReadinessStatus::Done);
    expect(VersionReadinessReview::first()->user_id)->toBe($user->id);
});

test('acknowledging cannot complete a required item that has no data', function () {
    $version = readinessVersion();

    app(VersionReadiness::class)->acknowledge($version, 'version.dates.teacher', null);

    expect(readinessStatus($version, 'version.dates.teacher'))->toBe(ReadinessStatus::NotStarted);
});

test('acknowledging an empty optional item means "we do not use this" and marks it done', function () {
    $version = readinessVersion();

    expect(readinessStatus($version, 'version.pitch_files'))->toBe(ReadinessStatus::NotStarted);

    app(VersionReadiness::class)->acknowledge($version, 'version.pitch_files', null);

    expect(readinessStatus($version, 'version.pitch_files'))->toBe(ReadinessStatus::Done);
});

test('review-required overrides otherwise complete data until acknowledged', function () {
    $version = readinessVersion();
    readinessSetDate($version, VersionDateType::Teacher);
    VersionReadinessReview::create(['version_id' => $version->id, 'item_key' => 'version.dates.teacher', 'state' => ReadinessReviewState::ReviewRequired]);

    expect(readinessStatus($version, 'version.dates.teacher'))->toBe(ReadinessStatus::NeedsReview);

    app(VersionReadiness::class)->acknowledge($version, 'version.dates.teacher', null);

    expect(readinessStatus($version, 'version.dates.teacher'))->toBe(ReadinessStatus::Done);
});

test('markSectionReviewed acknowledges only the items owned by that tab', function () {
    $version = readinessVersion();

    app(VersionReadiness::class)->markSectionReviewed($version, 'requirements', null);

    expect(readinessStatus($version, 'version.requirements.student_fields'))->toBe(ReadinessStatus::Done)
        ->and(readinessStatus($version, 'version.membership'))->toBe(ReadinessStatus::Done)
        ->and(readinessStatus($version, 'version.caps'))->toBe(ReadinessStatus::NeedsReview);
});

test('seedForClone flags year-sensitive items for review and carries other confirmations forward', function () {
    $version = readinessVersion();

    app(VersionReadiness::class)->seedForClone($version);

    $states = VersionReadinessReview::where('version_id', $version->id)->pluck('state', 'item_key');

    expect($states['version.fees'])->toBe(ReadinessReviewState::ReviewRequired)
        ->and($states['version.dates.teacher'])->toBe(ReadinessReviewState::ReviewRequired)
        ->and($states['version.caps'])->toBe(ReadinessReviewState::Acknowledged)
        ->and($states['version.requirements.student_fields'])->toBe(ReadinessReviewState::Acknowledged);
});

test('cloning a Version seeds its readiness reviews', function () {
    $source = readinessVersion();
    readinessSetDate($source, VersionDateType::Teacher);

    $clone = app(VersionCloningService::class)->cloneFrom(
        $source,
        ['name' => 'Next year', 'short_name' => null, 'senior_class_of' => 2028],
        User::factory()->create(),
    );

    expect(readinessStatus($clone, 'version.dates.teacher'))->toBe(ReadinessStatus::NeedsReview)
        ->and(readinessStatus($clone, 'version.caps'))->toBe(ReadinessStatus::Done);
});

test('room judges are in progress until every room has its full panel', function () {
    $version = readinessVersion(['judge_count' => 2]);
    $roomA = VersionRoom::create(['version_id' => $version->id, 'name' => 'A', 'order_by' => 1]);
    $roomB = VersionRoom::create(['version_id' => $version->id, 'name' => 'B', 'order_by' => 2]);
    RoomJudge::factory()->count(2)->create(['version_id' => $version->id, 'room_id' => $roomA->id]);
    RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $roomB->id]);

    $result = app(VersionReadiness::class)->evaluate($version)->get('version.room_judges');

    expect($result->status)->toBe(ReadinessStatus::InProgress)
        ->and($result->detail)->toBe('1 of 2 rooms fully staffed');
});

test('invitations held only by the Version\'s own role holders do not count as inviting teachers', function () {
    $version = readinessVersion();
    $manager = User::factory()->create();
    $teacher = Teacher::factory()->create(['user_id' => $manager->id]);
    grantVersionRole($manager, $version, 'Event Manager');
    VersionInvitation::create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'status' => VersionInvitationStatus::Invited->value, 'invited_at' => now(), 'invited_by_user_id' => $manager->id]);

    expect(readinessStatus($version, 'version.invitations'))->toBe(ReadinessStatus::NotStarted);
});

test('a fully configured Version has no blockers before registration but still has audition blockers', function () {
    $version = readinessConfiguredVersion();
    $readiness = app(VersionReadiness::class);

    expect($readiness->blockers($version, ReadinessPhase::BeforeRegistration)->keys()->all())->toBe([])
        ->and($readiness->blockers($version->fresh(), ReadinessPhase::BeforeAuditions)->keys()->all())
        ->toContain('version.dates.adjudication', 'version.rooms', 'version.roles.tab_room');
});

test('summary counts done items against applicable items only', function () {
    $version = readinessVersion();
    $readiness = app(VersionReadiness::class);

    $summary = $readiness->summary($readiness->evaluate($version));
    $applicable = $readiness->evaluate($version->fresh())->reject(fn ($r) => $r->status === ReadinessStatus::NotApplicable)->count();

    expect($summary['total'])->toBe($applicable)
        ->and($summary['phases'][ReadinessPhase::BeforeInvitations->value]['blocking'])->toBeGreaterThan(0);
});

test('the credential check never decrypts the secret, so a credential encrypted under another APP_KEY does not throw', function () {
    $version = readinessVersion();
    VersionEpaymentConfig::create(['version_id' => $version->id, 'epayment_student' => true, 'epayment_teacher' => false]);
    $config = EventEpaymentConfig::factory()->create([
        'event_id' => $version->event_id,
        'environment' => config('services.payments.environment', PaymentEnvironment::Sandbox->value),
    ]);

    // Simulate a row written with a different environment's APP_KEY.
    DB::table('event_epayment_configs')->where('id', $config->id)->update(['secret' => 'eyJpdiI6ImZvcmVpZ24ta2V5In0=']);

    expect(readinessStatus($version, 'event.epayment.credentials'))->toBe(ReadinessStatus::Done);
});
