<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\EventStatus;
use App\Enums\FeeType;
use App\Livewire\Events\Reports\ParticipatingSchools;
use App\Livewire\Events\Reports\PaymentReconciliation;
use App\Models\Candidate;
use App\Models\CandidateFeeWaiver;
use App\Models\Version;
use App\Models\VersionFee;
use App\Services\CandidateFeeCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the configured fee for each fee type when nothing is waived', function () {
    $version = Version::factory()->create();
    VersionFee::create(['version_id' => $version->id, 'registration' => 2000, 'participation' => 500, 'housing' => 7500]);
    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id]);

    expect(app(CandidateFeeCalculator::class)->feeCents($version, $candidate, FeeType::Registration))->toBe(2000)
        ->and(app(CandidateFeeCalculator::class)->feeCents($version, $candidate, FeeType::Participation))->toBe(500)
        ->and(app(CandidateFeeCalculator::class)->feeCents($version, $candidate, FeeType::Housing))->toBe(7500);
});

it('returns zero for every fee type when the version has no fees row', function () {
    $version = Version::factory()->create();
    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id]);

    expect(app(CandidateFeeCalculator::class)->feeCents($version, $candidate, FeeType::Registration))->toBe(0)
        ->and(app(CandidateFeeCalculator::class)->combinedDueCents($version, $candidate))->toBe(0);
});

it('zeroes only the waived fee type', function () {
    $version = Version::factory()->create();
    VersionFee::create(['version_id' => $version->id, 'registration' => 2000, 'participation' => 500, 'housing' => 7500]);
    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id]);
    CandidateFeeWaiver::factory()->forFeeType(FeeType::Housing)->create(['candidate_id' => $candidate->id]);

    $candidate->refresh();

    expect($candidate->hasFeeWaived(FeeType::Housing))->toBeTrue()
        ->and($candidate->hasFeeWaived(FeeType::Registration))->toBeFalse()
        ->and(app(CandidateFeeCalculator::class)->feeCents($version, $candidate, FeeType::Housing))->toBe(0)
        ->and(app(CandidateFeeCalculator::class)->feeCents($version, $candidate, FeeType::Registration))->toBe(2000);
});

it('adds participation to combined due only once closed and only for accepted candidates', function () {
    $open = Version::factory()->create();
    VersionFee::create(['version_id' => $open->id, 'registration' => 2000, 'participation' => 500]);
    $closed = Version::factory()->create(['status' => EventStatus::Closed]);
    VersionFee::create(['version_id' => $closed->id, 'registration' => 2000, 'participation' => 500]);

    $acceptedOpen = Candidate::factory()->create(['version_id' => $open->id, 'status' => CandidateStatus::Accepted]);
    $acceptedClosed = Candidate::factory()->create(['version_id' => $closed->id, 'status' => CandidateStatus::Accepted]);
    $notAcceptedClosed = Candidate::factory()->create(['version_id' => $closed->id, 'status' => CandidateStatus::NotAccepted]);

    expect(app(CandidateFeeCalculator::class)->combinedDueCents($open, $acceptedOpen))->toBe(2000)
        ->and(app(CandidateFeeCalculator::class)->combinedDueCents($closed, $acceptedClosed))->toBe(2500)
        ->and(app(CandidateFeeCalculator::class)->combinedDueCents($closed, $notAcceptedClosed))->toBe(2000);
});

it('applies waivers to each part of combined due independently', function () {
    $version = Version::factory()->create(['status' => EventStatus::Closed]);
    VersionFee::create(['version_id' => $version->id, 'registration' => 2000, 'participation' => 500]);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'status' => CandidateStatus::Accepted]);
    CandidateFeeWaiver::factory()->forFeeType(FeeType::Registration)->create(['candidate_id' => $candidate->id]);

    expect(app(CandidateFeeCalculator::class)->combinedDueCents($version, $candidate->refresh()))->toBe(500);

    CandidateFeeWaiver::factory()->forFeeType(FeeType::Participation)->create(['candidate_id' => $candidate->id]);

    expect(app(CandidateFeeCalculator::class)->combinedDueCents($version, $candidate->refresh()))->toBe(0);
});

it('sums a fee type across candidates, skipping waived ones', function () {
    $version = Version::factory()->create();
    VersionFee::create(['version_id' => $version->id, 'registration' => 2000]);
    $candidates = Candidate::factory()->registered()->count(3)->create(['version_id' => $version->id]);
    CandidateFeeWaiver::factory()->create(['candidate_id' => $candidates->first()->id]);

    $fresh = Candidate::whereIn('id', $candidates->pluck('id'))->get();

    expect(app(CandidateFeeCalculator::class)->sumFeeCents($version, $fresh, FeeType::Registration))->toBe(4000);
});

it('reflects a registration waiver in the Event Manager school reports', function () {
    $version = Version::factory()->create();
    VersionFee::create(['version_id' => $version->id, 'registration' => 2000]);
    $waived = Candidate::factory()->registered()->create(['version_id' => $version->id]);
    Candidate::factory()->registered()->create([
        'version_id' => $version->id,
        'school_id' => $waived->school_id,
        'teacher_id' => $waived->teacher_id,
    ]);
    CandidateFeeWaiver::factory()->create(['candidate_id' => $waived->id]);

    $schoolsRow = ParticipatingSchools::baseRows($version, null)->first();
    $reconciliationRow = PaymentReconciliation::baseRows($version, null)->first();

    expect($schoolsRow['dueCents'])->toBe(2000)
        ->and($schoolsRow['count'])->toBe(2)
        ->and($reconciliationRow['dueCents'])->toBe(2000);
});
