<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\FeeType;
use App\Models\Version;
use App\Models\VersionFee;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('FeeType::Housing has a label', function () {
    expect(FeeType::Housing->label())->toBe('Housing Fee');
});

test('housingFeePayable mirrors participationFeePayable\'s timing — false until the Version is closed', function (EventStatus $status) {
    $version = Version::factory()->create(['status' => $status]);

    expect($version->housingFeePayable())->toBeFalse();
})->with([EventStatus::Sandbox, EventStatus::Active, EventStatus::Inactive]);

test('housingFeePayable is true once the Version is closed', function () {
    $version = Version::factory()->create(['status' => EventStatus::Closed]);

    expect($version->housingFeePayable())->toBeTrue();
});

test('VersionFee::amountForCheckout computes the housing amount per candidate plus the surcharge once, not per candidate', function () {
    $version = Version::factory()->create();
    $fees = VersionFee::create([
        'version_id' => $version->id,
        'registration' => 2000,
        'participation' => 500,
        'housing' => 1500,
        'epayment_surcharge' => 100,
    ]);

    expect($fees->amountForCheckout(FeeType::Housing, 3))->toBe((1500 * 3) + 100);
});

test('VersionFee::housingInDollars converts cents to dollars', function () {
    $version = Version::factory()->create();
    $fees = VersionFee::create(['version_id' => $version->id, 'housing' => 1550]);

    expect($fees->housingInDollars())->toBe(15.5);
});
