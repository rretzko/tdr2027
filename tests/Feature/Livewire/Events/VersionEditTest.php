<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\EventStatus;
use App\Enums\PitchFileVisibility;
use App\Enums\ScoreOrder;
use App\Enums\UploadType;
use App\Enums\VersionDateType;
use App\Livewire\Events\VersionEdit;
use App\Models\County;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionEnsembleOrder;
use App\Models\VersionFee;
use App\Models\VersionMembershipRequirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeVersionEditUser(): User
{
    $user = User::factory()->create();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    return $user;
}

test('mount populates the general tab fields from the Version', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create(['name' => 'Spring 2027 Chorus', 'judge_count' => 3]);

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->assertSet('name', 'Spring 2027 Chorus')
        ->assertSet('judge_count', '3');
});

test('saveGeneral updates the Version record', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create(['name' => 'Old Name']);

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('name', 'New Name')
        ->set('status', EventStatus::Active->value)
        ->set('application_type', ApplicationType::Pdf->value)
        ->set('audition_type', AuditionType::Remote->value)
        ->set('upload_type', UploadType::None->value)
        ->set('score_order', ScoreOrder::Asc->value)
        ->set('pitch_file_visibility', PitchFileVisibility::Both->value)
        ->call('saveGeneral')
        ->assertHasNoErrors();

    expect($version->fresh()->name)->toBe('New Name');
    expect($version->fresh()->status)->toBe(EventStatus::Active);
});

test('saveGeneral requires a name', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('name', '')
        ->call('saveGeneral')
        ->assertHasErrors('name');
});

test('saveDates creates rows for each populated VersionDateType', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('date_start.'.VersionDateType::Teacher->value, '2027-01-01T09:00')
        ->set('date_start.'.VersionDateType::Adjudication->value, '2027-02-01T09:00')
        ->set('date_end.'.VersionDateType::Adjudication->value, '2027-02-02T17:00')
        ->call('saveDates')
        ->assertHasNoErrors();

    expect(VersionDate::where('version_id', $version->id)->count())->toBe(2);

    $adjudication = VersionDate::where('version_id', $version->id)
        ->where('date_type', VersionDateType::Adjudication->value)
        ->first();

    expect($adjudication->getRawOriginal('end_at'))->not->toBeNull();
});

test('saveDates rejects an end_at before start_at for date types with a window', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('date_start.'.VersionDateType::Adjudication->value, '2027-02-02T09:00')
        ->set('date_end.'.VersionDateType::Adjudication->value, '2027-02-01T09:00')
        ->call('saveDates')
        ->assertHasErrors('date_end.'.VersionDateType::Adjudication->value);
});

test('saveDates removes a date row when its start is cleared', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();

    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => VersionDateType::Teacher->value,
        'start_at' => now(),
        'end_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('date_start.'.VersionDateType::Teacher->value, '')
        ->call('saveDates')
        ->assertHasNoErrors();

    expect(VersionDate::where('version_id', $version->id)->where('date_type', VersionDateType::Teacher->value)->exists())->toBeFalse();
});

test('saveFees converts dollar input to cents on the VersionFee row', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('fee_registration', '25.00')
        ->set('fee_on_site_registration', '0')
        ->set('fee_participation', '75.50')
        ->set('fee_epayment_surcharge', '2.50')
        ->set('fee_housing', '0')
        ->call('saveFees')
        ->assertHasNoErrors();

    $fee = VersionFee::where('version_id', $version->id)->first();

    expect($fee->registration)->toBe(2500);
    expect($fee->participation)->toBe(7550);
    expect($fee->epayment_surcharge)->toBe(250);
});

test('saveRequirements saves membership requirement and syncs selected counties', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();
    $countyA = County::factory()->create();
    $countyB = County::factory()->create();

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('membership_card', true)
        ->set('membership_valid_thru', '2027-08-31')
        ->set('selected_county_ids', [$countyA->id, $countyB->id])
        ->call('saveRequirements')
        ->assertHasNoErrors();

    $requirement = VersionMembershipRequirement::where('version_id', $version->id)->first();
    expect($requirement->membership_card)->toBeTrue();

    expect($version->fresh()->counties->pluck('county_id')->all())->toEqualCanonicalizing([$countyA->id, $countyB->id]);
});

test('saveEnsembleOrder persists the order_by value for each ensemble', function () {
    $user = makeVersionEditUser();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    $first = Ensemble::factory()->create(['event_id' => $event->id]);
    $second = Ensemble::factory()->create(['event_id' => $event->id]);

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set("ensemble_order.{$first->id}", 2)
        ->set("ensemble_order.{$second->id}", 1)
        ->call('saveEnsembleOrder')
        ->assertHasNoErrors();

    expect(VersionEnsembleOrder::where('version_id', $version->id)->where('ensemble_id', $first->id)->value('order_by'))->toBe(2);
    expect(VersionEnsembleOrder::where('version_id', $version->id)->where('ensemble_id', $second->id)->value('order_by'))->toBe(1);
});
