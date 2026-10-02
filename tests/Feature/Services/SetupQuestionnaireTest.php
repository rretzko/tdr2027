<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\ReadinessStatus;
use App\Enums\TeacherPayments;
use App\Enums\UploadType;
use App\Models\County;
use App\Models\Ensemble;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionCounty;
use App\Services\Readiness\SetupAnswers;
use App\Services\Readiness\SetupQuestionnaire;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 12:00:00');
});

function setupApply(Version $version, SetupAnswers $answers): void
{
    app(SetupQuestionnaire::class)->apply($version, $answers, User::factory()->create());
}

test('blank answers write nothing and confirm nothing', function () {
    $version = readinessVersion();
    $before = $version->fresh()->getAttributes();

    setupApply($version, new SetupAnswers);

    expect($version->fresh()->getAttributes())->toBe($before)
        ->and($version->readinessReviews()->count())->toBe(0)
        ->and($version->uploadFiles()->count())->toBe(0);
});

test('recordings, audio, online application, nothing mailed', function () {
    $version = readinessVersion(['application_type' => ApplicationType::Pdf, 'mail_required' => true]);

    setupApply($version, new SetupAnswers(
        auditionType: AuditionType::Remote,
        uploadType: UploadType::Audio,
        recordingNames: ['Scales', 'Solo', 'Sight-reading'],
        applicationType: ApplicationType::EApplication,
        mailRequired: false,
    ));

    $version->refresh();
    expect($version->getRawOriginal('audition_type'))->toBe('remote')
        ->and($version->getRawOriginal('upload_type'))->toBe('audio')
        ->and($version->getRawOriginal('application_type'))->toBe('eapplication')
        ->and($version->mail_required)->toBeFalse()
        ->and($version->uploadFiles()->orderBy('order_by')->pluck('name')->all())->toBe(['Scales', 'Solo', 'Sight-reading'])
        ->and(readinessStatus($version, 'version.audition_format'))->toBe(ReadinessStatus::Done)
        ->and(readinessStatus($version, 'version.upload_files'))->toBe(ReadinessStatus::Done)
        ->and(readinessStatus($version, 'version.dates.postmark_deadline'))->toBe(ReadinessStatus::NotApplicable)
        ->and(readinessStatus($version, 'version.roles.mail_to'))->toBe(ReadinessStatus::NotApplicable);
});

test('recordings without audio-or-video set the audition type but leave the format unconfirmed', function () {
    $version = readinessVersion();

    setupApply($version, new SetupAnswers(auditionType: AuditionType::Remote, applicationType: ApplicationType::Pdf));

    expect(readinessStatus($version, 'version.audition_format'))->toBe(ReadinessStatus::NeedsReview);
});

test('in person means no uploads, with the audition length defaulting to 20 minutes', function () {
    $version = readinessVersion(['upload_type' => UploadType::Video]);

    setupApply($version, new SetupAnswers(auditionType: AuditionType::InPerson, applicationType: ApplicationType::Pdf));

    $version->refresh();
    expect($version->getRawOriginal('audition_type'))->toBe('in_person')
        ->and($version->getRawOriginal('upload_type'))->toBe('none')
        ->and($version->audition_timeslot)->toBe(20)
        ->and(readinessStatus($version, 'version.audition_format'))->toBe(ReadinessStatus::Done)
        ->and(readinessStatus($version, 'version.upload_files'))->toBe(ReadinessStatus::NotApplicable);
});

test('recording names never duplicate or replace an existing list', function () {
    $version = readinessVersion();
    $version->uploadFiles()->create(['name' => 'Existing', 'order_by' => 1]);

    setupApply($version, new SetupAnswers(auditionType: AuditionType::Remote, uploadType: UploadType::Audio, recordingNames: ['New']));

    expect($version->uploadFiles()->pluck('name')->all())->toBe(['Existing']);
});

test('switching to in person keeps any recordings already listed', function () {
    $version = readinessVersion(['upload_type' => UploadType::Audio]);
    $version->uploadFiles()->create(['name' => 'Solo', 'order_by' => 1]);

    setupApply($version, new SetupAnswers(auditionType: AuditionType::InPerson));

    expect($version->uploadFiles()->count())->toBe(1);
});

test('membership card answers write the requirement and confirm it', function () {
    $version = readinessVersion();

    setupApply($version, new SetupAnswers(membershipCard: true, membershipValidThru: '2027-06-30'));

    $requirement = $version->membershipRequirement()->first();
    expect((bool) $requirement->membership_card)->toBeTrue()
        ->and($requirement->getRawOriginal('valid_thru'))->toStartWith('2027-06-30')
        ->and(readinessStatus($version, 'version.membership'))->toBe(ReadinessStatus::Done);

    setupApply($version, new SetupAnswers(membershipCard: false, membershipValidThru: '2027-06-30'));

    $requirement->refresh();
    expect((bool) $requirement->membership_card)->toBeFalse()
        ->and($requirement->valid_thru)->toBeNull();
});

test('students paying online and teachers settling online or by check is a valid combination', function () {
    $version = readinessVersion();

    setupApply($version, new SetupAnswers(teacherPayments: TeacherPayments::OnlineOrCheck, studentPayments: true));

    $config = $version->versionEpaymentConfig()->first();
    expect($config->epayment_teacher)->toBeTrue()
        ->and($config->epayment_student)->toBeTrue()
        ->and($config->online_payment_required)->toBeFalse()
        ->and($version->fresh()->onlinePaymentRequired())->toBeFalse()
        ->and(readinessStatus($version, 'version.epayment.decision'))->toBe(ReadinessStatus::Done)
        ->and(readinessStatus($version, 'event.epayment.credentials'))->toBe(ReadinessStatus::NotStarted);
});

test('"online only" requires teachers to settle online', function () {
    $version = readinessVersion();

    setupApply($version, new SetupAnswers(teacherPayments: TeacherPayments::OnlineOnly, studentPayments: false));

    $config = $version->versionEpaymentConfig()->first();
    expect($config->epayment_teacher)->toBeTrue()
        ->and($config->epayment_student)->toBeFalse()
        ->and($config->online_payment_required)->toBeTrue()
        ->and($version->fresh()->onlinePaymentRequired())->toBeTrue();
});

test('students may pay online while teachers settle the rest by check', function () {
    $version = readinessVersion();

    setupApply($version, new SetupAnswers(teacherPayments: TeacherPayments::CheckOnly, studentPayments: true));

    $config = $version->versionEpaymentConfig()->first();
    expect($config->epayment_teacher)->toBeFalse()
        ->and($config->epayment_student)->toBeTrue()
        ->and($config->online_payment_required)->toBeFalse()
        ->and(readinessStatus($version, 'event.epayment.credentials'))->toBe(ReadinessStatus::NotStarted);
});

test('answering only one payment question writes it but leaves the payment decision open', function () {
    $version = readinessVersion();

    setupApply($version, new SetupAnswers(studentPayments: true));

    $config = $version->versionEpaymentConfig()->first();
    expect($config->epayment_student)->toBeTrue()
        ->and($config->epayment_teacher)->toBeFalse()
        ->and(readinessStatus($version, 'version.epayment.decision'))->toBe(ReadinessStatus::NeedsReview);
});

test('ensembles are created only when the event has none', function () {
    $version = readinessVersion();

    setupApply($version, new SetupAnswers(ensembleNames: ['Mixed Chorus', 'Treble Choir']));
    setupApply($version, new SetupAnswers(ensembleNames: ['Should Not Appear']));

    expect(Ensemble::where('event_id', $version->event_id)->orderBy('name')->pluck('name')->all())
        ->toBe(['Mixed Chorus', 'Treble Choir'])
        ->and(readinessStatus($version, 'event.ensembles'))->toBe(ReadinessStatus::Done)
        ->and(readinessStatus($version, 'version.ensemble_order'))->toBe(ReadinessStatus::NotStarted);
});

test('judge count is written and confirmed', function () {
    $version = readinessVersion();

    setupApply($version, new SetupAnswers(judgeCount: 3));

    expect($version->fresh()->judge_count)->toBe(3)
        ->and(readinessStatus($version, 'version.judge_count'))->toBe(ReadinessStatus::Done);
});

test('"no" confirms an optional feature as unused; "yes" leaves it open', function () {
    $version = readinessVersion();

    setupApply($version, new SetupAnswers(optional: [
        'version.obligations' => false,
        'version.pitch_files' => true,
        'version.caps' => false,
    ]));

    expect(readinessStatus($version, 'version.obligations'))->toBe(ReadinessStatus::Done)
        ->and(readinessStatus($version, 'version.caps'))->toBe(ReadinessStatus::Done)
        ->and(readinessStatus($version, 'version.pitch_files'))->toBe(ReadinessStatus::NotStarted)
        ->and(readinessStatus($version, 'version.counties'))->toBe(ReadinessStatus::NeedsReview);
});

test('"no" is ignored for a feature that already has data, and nothing is deleted', function () {
    $version = readinessVersion();
    VersionCounty::create(['version_id' => $version->id, 'county_id' => County::factory()->create()->id]);

    setupApply($version, new SetupAnswers(optional: ['version.counties' => false]));

    expect($version->counties()->count())->toBe(1)
        ->and($version->readinessReviews()->where('item_key', 'version.counties')->exists())->toBeFalse();
});

test('a first run starts blank, and a re-run is pre-filled with what was answered', function () {
    $version = readinessVersion();
    $questionnaire = app(SetupQuestionnaire::class);

    $first = $questionnaire->current($version);
    expect($first->auditionType)->toBeNull()
        ->and($first->applicationType)->toBeNull()
        ->and($first->mailRequired)->toBeNull()
        ->and($first->teacherPayments)->toBeNull()
        ->and($first->studentPayments)->toBeNull()
        ->and($first->judgeCount)->toBeNull()
        ->and(array_filter($first->optional, fn ($v) => $v !== null))->toBe([]);

    setupApply($version, new SetupAnswers(
        auditionType: AuditionType::Remote,
        uploadType: UploadType::Video,
        applicationType: ApplicationType::EApplication,
        mailRequired: true,
        teacherPayments: TeacherPayments::OnlineOnly,
        studentPayments: true,
        judgeCount: 2,
        optional: ['version.caps' => false],
    ));

    $rerun = $questionnaire->current($version->fresh());
    expect($rerun->auditionType)->toBe(AuditionType::Remote)
        ->and($rerun->uploadType)->toBe(UploadType::Video)
        ->and($rerun->applicationType)->toBe(ApplicationType::EApplication)
        ->and($rerun->mailRequired)->toBeTrue()
        ->and($rerun->teacherPayments)->toBe(TeacherPayments::OnlineOnly)
        ->and($rerun->studentPayments)->toBeTrue()
        ->and($rerun->judgeCount)->toBe(2)
        ->and($rerun->optional['version.caps'])->toBeFalse();
});

test('a cap of 0 on total or upper-voice registrants means no limit, so caps are not "already set up"', function () {
    $version = readinessVersion(['max_registrants' => 0, 'max_upper_voice_registrants' => 0, 'audition_cap_per_school' => null]);

    expect(app(SetupQuestionnaire::class)->existingOptionalSetup($version)['version.caps'])->toBeNull();
});

test('real caps count as already set up, including a per-school cap of 0', function () {
    $questionnaire = app(SetupQuestionnaire::class);

    expect($questionnaire->existingOptionalSetup(readinessVersion(['max_registrants' => 30]))['version.caps'])->toBe('1 cap set')
        ->and($questionnaire->existingOptionalSetup(readinessVersion(['max_upper_voice_registrants' => 35]))['version.caps'])->toBe('1 cap set')
        ->and($questionnaire->existingOptionalSetup(readinessVersion(['audition_cap_per_school' => 0]))['version.caps'])->toBe('1 cap set');
});
