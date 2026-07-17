<?php

declare(strict_types=1);

use App\Enums\VersionApplicationStatus;
use App\Enums\VersionDateType;
use App\Enums\VersionInvitationStatus;
use App\Enums\VersionObligationStatus;
use App\Models\County;
use App\Models\Ensemble;
use App\Models\EpaymentCredential;
use App\Models\Event;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionApplication;
use App\Models\VersionCounty;
use App\Models\VersionDate;
use App\Models\VersionEnsembleOrder;
use App\Models\VersionFee;
use App\Models\VersionInvitation;
use App\Models\VersionMembershipRequirement;
use App\Models\VersionObligation;
use App\Models\VersionPitchFile;
use App\Models\VersionUploadFile;
use App\Models\VoicePart;
use App\Services\VersionCloningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function buildFullyConfiguredVersion(): Version
{
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'senior_class_of' => 2027]);

    VersionFee::create([
        'version_id' => $version->id,
        'registration' => 2000,
        'on_site_registration' => 0,
        'participation' => 5000,
        'epayment_surcharge' => 0,
        'housing' => 0,
    ]);

    VersionMembershipRequirement::create([
        'version_id' => $version->id,
        'membership_card' => true,
        'valid_thru' => '2027-06-30',
    ]);

    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => VersionDateType::Teacher->value,
        'start_at' => '2027-01-01 00:00:00',
        'end_at' => null,
    ]);

    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => VersionDateType::Adjudication->value,
        'start_at' => '2027-02-01 09:00:00',
        'end_at' => '2027-02-01 17:00:00',
    ]);

    $county = County::factory()->create();
    VersionCounty::create(['version_id' => $version->id, 'county_id' => $county->id]);

    $ensemble = Ensemble::factory()->create(['event_id' => $event->id]);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $ensemble->id, 'order_by' => 1]);

    $voicePart = VoicePart::factory()->create();
    VersionPitchFile::create([
        'version_id' => $version->id,
        'voice_part_id' => $voicePart->id,
        'name' => 'scales',
        'description' => 'Warm-up scales',
        'url' => 'https://example.test/scales.mp3',
        'order_by' => 1,
    ]);

    VersionUploadFile::create(['version_id' => $version->id, 'name' => 'solo', 'order_by' => 1]);

    VersionObligation::create([
        'version_id' => $version->id,
        'title' => 'Director Obligations',
        'body' => '<p>Body text</p>',
        'status' => VersionObligationStatus::Published->value,
        'published_at' => now(),
        'published_by_user_id' => User::factory()->create()->id,
    ]);

    VersionApplication::create([
        'version_id' => $version->id,
        'student_endorsement_body' => '<p>Student</p>',
        'parent_endorsement_body' => '<p>Parent</p>',
        'teacher_principal_endorsement_body' => '<p>Principal</p>',
        'schedule_body' => '<p>Schedule</p>',
        'policies_body' => '<p>Policies</p>',
        'status' => VersionApplicationStatus::Published->value,
        'published_at' => now(),
        'published_by_user_id' => User::factory()->create()->id,
    ]);

    EpaymentCredential::create([
        'version_id' => $version->id,
        'epayment_id' => 'epay-123',
        'secret' => 'super-secret',
    ]);

    $inviter = User::factory()->create();
    $invitedTeacher = Teacher::factory()->create();
    $rejectedTeacher = Teacher::factory()->create();

    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $invitedTeacher->id,
        'status' => VersionInvitationStatus::Obligated->value,
        'invited_at' => now(),
        'invited_by_user_id' => $inviter->id,
    ]);

    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $rejectedTeacher->id,
        'status' => VersionInvitationStatus::Rejected->value,
        'invited_at' => now(),
        'invited_by_user_id' => $inviter->id,
    ]);

    return $version->fresh();
}

test('cloneFrom copies scalar Version fields and identity overrides', function () {
    $source = buildFullyConfiguredVersion();
    $invitedBy = User::factory()->create();

    $clone = app(VersionCloningService::class)->cloneFrom($source, [
        'name' => 'Cloned Version',
        'short_name' => 'Clone',
        'senior_class_of' => 2028,
    ], $invitedBy);

    expect($clone->name)->toBe('Cloned Version');
    expect($clone->short_name)->toBe('Clone');
    expect($clone->senior_class_of)->toBe(2028);
    expect($clone->event_id)->toBe($source->event_id);
    expect($clone->audition_timeslot)->toBe($source->audition_timeslot);
    expect($clone->getRawOriginal('status'))->toBe('sandbox');
});

test('cloneFrom advances VersionDate rows by one year and preserves date_type', function () {
    $source = buildFullyConfiguredVersion();

    $clone = app(VersionCloningService::class)->cloneFrom($source, [
        'name' => 'Cloned Version', 'short_name' => null, 'senior_class_of' => 2028,
    ], User::factory()->create());

    $dates = $clone->dates()->get()->keyBy(fn ($date) => $date->getRawOriginal('date_type'));

    expect($dates)->toHaveCount(2);
    expect(Carbon::parse($dates[VersionDateType::Teacher->value]->getRawOriginal('start_at'))->toDateString())->toBe('2028-01-01');
    expect($dates[VersionDateType::Teacher->value]->getRawOriginal('end_at'))->toBeNull();
    expect(Carbon::parse($dates[VersionDateType::Adjudication->value]->getRawOriginal('start_at'))->toDateString())->toBe('2028-02-01');
    expect(Carbon::parse($dates[VersionDateType::Adjudication->value]->getRawOriginal('end_at'))->toDateString())->toBe('2028-02-01');
});

test('cloneFrom advances membershipRequirement valid_thru by one year', function () {
    $source = buildFullyConfiguredVersion();

    $clone = app(VersionCloningService::class)->cloneFrom($source, [
        'name' => 'Cloned Version', 'short_name' => null, 'senior_class_of' => 2028,
    ], User::factory()->create());

    expect(Carbon::parse($clone->membershipRequirement->getRawOriginal('valid_thru'))->toDateString())->toBe('2028-06-30');
    expect($clone->membershipRequirement->membership_card)->toBeTrue();
});

test('cloneFrom copies fees, counties, ensemble order, pitch files, upload files, and epayment credential', function () {
    $source = buildFullyConfiguredVersion();

    $clone = app(VersionCloningService::class)->cloneFrom($source, [
        'name' => 'Cloned Version', 'short_name' => null, 'senior_class_of' => 2028,
    ], User::factory()->create());

    expect($clone->fees->registration)->toBe(2000);
    expect($clone->fees->participation)->toBe(5000);
    expect($clone->counties)->toHaveCount(1);
    expect($clone->counties->first()->county_id)->toBe($source->counties->first()->county_id);
    expect($clone->ensembleOrder)->toHaveCount(1);
    expect($clone->ensembleOrder->first()->ensemble_id)->toBe($source->ensembleOrder->first()->ensemble_id);
    expect($clone->pitchFiles)->toHaveCount(1);
    expect($clone->pitchFiles->first()->name)->toBe('scales');
    expect($clone->uploadFiles)->toHaveCount(1);
    expect($clone->uploadFiles->first()->name)->toBe('solo');
    expect($clone->epaymentCredential->epayment_id)->toBe('epay-123');
    expect($clone->epaymentCredential->secret)->toBe('super-secret');
});

test('cloneFrom resets obligation and application to draft with no publish metadata', function () {
    $source = buildFullyConfiguredVersion();

    $clone = app(VersionCloningService::class)->cloneFrom($source, [
        'name' => 'Cloned Version', 'short_name' => null, 'senior_class_of' => 2028,
    ], User::factory()->create());

    expect($clone->obligation->body)->toBe('<p>Body text</p>');
    expect($clone->obligation->getRawOriginal('status'))->toBe('draft');
    expect($clone->obligation->published_at)->toBeNull();
    expect($clone->obligation->published_by_user_id)->toBeNull();

    expect($clone->candidateApplication->student_endorsement_body)->toBe('<p>Student</p>');
    expect($clone->candidateApplication->getRawOriginal('status'))->toBe('draft');
    expect($clone->candidateApplication->published_at)->toBeNull();
    expect($clone->candidateApplication->published_by_user_id)->toBeNull();
});

test('cloneFrom excludes rejected invitations and resets survivors to invited with fresh invited_at/invited_by_user_id', function () {
    $source = buildFullyConfiguredVersion();
    $invitedBy = User::factory()->create();

    $clone = app(VersionCloningService::class)->cloneFrom($source, [
        'name' => 'Cloned Version', 'short_name' => null, 'senior_class_of' => 2028,
    ], $invitedBy);

    $invitations = $clone->invitations()->get();

    expect($invitations)->toHaveCount(1);
    expect($invitations->first()->getRawOriginal('status'))->toBe('invited');
    expect($invitations->first()->invited_by_user_id)->toBe($invitedBy->id);
    expect($invitations->first()->invited_at)->not->toBeNull();
});
