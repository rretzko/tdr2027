<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\VersionDateType;
use App\Models\Candidate;
use App\Models\CandidateStatusHistory;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Services\RegistrationTimingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    // CandidateObserver writes candidate_status_history rows keyed by Auth::id().
    actingAs(User::factory()->create());
});

/**
 * A Candidate first registered at $at (UTC). candidate_status_history has no
 * Eloquent timestamps (created_at is the database default), so the observer's
 * rows are re-stamped here.
 */
function timingRegisteredAt(Version $version, string $at, CandidateStatus $finalStatus = CandidateStatus::Registered): Candidate
{
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'status' => CandidateStatus::Pending]);
    $candidate->update(['status' => CandidateStatus::Registered]);
    timingStampLatest($candidate, $at);

    if ($finalStatus !== CandidateStatus::Registered) {
        $candidate->update(['status' => $finalStatus]);
        timingStampLatest($candidate, Carbon::parse($at)->addDay()->toDateTimeString());
    }

    return $candidate;
}

function timingStampLatest(Candidate $candidate, string $at): void
{
    $id = CandidateStatusHistory::where('candidate_id', $candidate->id)->max('id');
    CandidateStatusHistory::whereKey($id)->update(['created_at' => $at]);
}

/**
 * Student deadline Mon Oct 26 6pm ET; Postmark Deadline Mon Nov 2 5pm ET.
 */
function timingVersion(): Version
{
    $version = Version::factory()->create();
    VersionDate::create(['version_id' => $version->id, 'date_type' => VersionDateType::Candidate, 'start_at' => '2026-10-05 04:00:00', 'end_at' => '2026-10-26 22:00:00']);
    VersionDate::create(['version_id' => $version->id, 'date_type' => VersionDateType::PostmarkDeadline, 'start_at' => '2026-11-02 22:00:00']);

    return $version;
}

test('there is nothing to chart before anyone is registered', function () {
    $version = timingVersion();
    Candidate::factory()->create(['version_id' => $version->id, 'status' => CandidateStatus::Pending]);

    expect(app(RegistrationTimingService::class)->forVersion($version))->toBeNull();
});

test('registrations are counted by Eastern day and split by the student and Postmark deadlines', function () {
    $version = timingVersion();
    timingRegisteredAt($version, '2026-10-20 15:00:00');
    timingRegisteredAt($version, '2026-10-26 21:00:00'); // 5pm ET, before the 6pm student deadline
    timingRegisteredAt($version, '2026-10-26 23:00:00'); // 7pm ET, same day, after it
    timingRegisteredAt($version, '2026-10-30 03:00:00'); // Oct 29 11pm ET
    timingRegisteredAt($version, '2026-11-03 15:00:00'); // after the Postmark Deadline

    $timing = app(RegistrationTimingService::class)->forVersion($version, Carbon::parse('2026-11-20'));
    $byDate = collect($timing['rows'])->keyBy('date');

    expect($timing['total'])->toBe(5)
        ->and($timing['rows'][0]['date'])->toBe('2026-10-20')
        ->and(end($timing['rows'])['date'])->toBe('2026-11-03')
        ->and($byDate['2026-10-26'])->toMatchArray(['x' => "\u{2212}7", 'early' => 1, 'middle' => 1, 'late' => 0])
        ->and($byDate['2026-10-26']['heading'])->toContain('Student deadline')
        ->and($byDate['2026-10-29'])->toMatchArray(['middle' => 1])
        ->and($byDate['2026-10-30'])->toMatchArray(['early' => 0, 'middle' => 0, 'late' => 0])
        ->and($byDate['2026-11-02'])->toMatchArray(['x' => '0'])
        ->and($byDate['2026-11-03'])->toMatchArray(['x' => '+1', 'late' => 1])
        ->and($timing['phases'])->toBe([
            ['field' => 'early', 'label' => 'By the student deadline', 'count' => 2, 'percent' => 40],
            ['field' => 'middle', 'label' => 'Between the deadlines', 'count' => 2, 'percent' => 40],
            ['field' => 'late', 'label' => 'After the Postmark Deadline', 'count' => 1, 'percent' => 20],
        ]);
});

test('the Final Teacher Changes date is the cut-off when set, ahead of the Postmark Deadline', function () {
    $version = timingVersion(); // Postmark Deadline Mon Nov 2 5pm ET
    VersionDate::create(['version_id' => $version->id, 'date_type' => VersionDateType::FinalTeacherChanges, 'start_at' => '2026-10-31 03:59:00']); // Fri Oct 30 11:59pm ET
    timingRegisteredAt($version, '2026-10-30 15:00:00'); // before Final Teacher Changes
    timingRegisteredAt($version, '2026-11-01 15:00:00'); // after it, before the Postmark Deadline

    $timing = app(RegistrationTimingService::class)->forVersion($version, Carbon::parse('2026-11-20'));
    $byDate = collect($timing['rows'])->keyBy('date');

    expect($timing['cutoffName'])->toBe('the Final Teacher Changes date')
        ->and($timing['cutoff']->toDateString())->toBe('2026-10-30')
        ->and($byDate['2026-10-30'])->toMatchArray(['x' => '0', 'middle' => 1, 'late' => 0])
        ->and($byDate['2026-10-30']['heading'])->toContain('The Final Teacher Changes date')
        ->and($byDate['2026-11-01'])->toMatchArray(['x' => '+2', 'late' => 1])
        ->and(end($timing['rows'])['date'])->toBe('2026-11-01')
        ->and(array_column($timing['phases'], 'label'))->toBe(['Between the deadlines', 'After the Final Teacher Changes date']);
});

test('only current registrants count, each once on the day they were first registered', function () {
    $version = timingVersion();
    $twice = timingRegisteredAt($version, '2026-10-20 15:00:00');
    $twice->update(['status' => CandidateStatus::Pending]);
    $twice->update(['status' => CandidateStatus::Registered]);
    timingStampLatest($twice, '2026-10-28 15:00:00');
    timingRegisteredAt($version, '2026-10-21 15:00:00', CandidateStatus::Accepted);
    timingRegisteredAt($version, '2026-10-22 15:00:00', CandidateStatus::TeacherWithdrawn);

    $timing = app(RegistrationTimingService::class)->forVersion($version, Carbon::parse('2026-11-20'));
    $byDate = collect($timing['rows'])->keyBy('date');

    expect($timing['total'])->toBe(2)
        ->and($byDate['2026-10-20']['early'])->toBe(1)
        ->and($byDate['2026-10-21']['early'])->toBe(1)
        ->and($byDate['2026-10-22']['early'])->toBe(0)
        ->and($byDate['2026-10-28']['middle'])->toBe(0);
});

test('the axis runs through a passed Postmark Deadline but not a future one', function () {
    $version = timingVersion();
    timingRegisteredAt($version, '2026-10-20 15:00:00');

    $service = app(RegistrationTimingService::class);

    expect(end($service->forVersion($version, Carbon::parse('2026-10-25'))['rows'])['date'])->toBe('2026-10-20')
        ->and(end($service->forVersion($version, Carbon::parse('2026-11-20'))['rows'])['date'])->toBe('2026-11-02');
});

test('without deadlines the days are labeled by date and everything is one phase', function () {
    $version = Version::factory()->create();
    timingRegisteredAt($version, '2026-10-20 15:00:00');

    $timing = app(RegistrationTimingService::class)->forVersion($version);

    expect($timing['rows'][0]['x'])->toBe('Oct 20')
        ->and($timing['phases'])->toBe([['field' => 'middle', 'label' => 'Registered', 'count' => 1, 'percent' => 100]]);
});

test('the Registration tab shows the registration timing chart', function () {
    $version = timingVersion();
    timingRegisteredAt($version, '2026-10-27 15:00:00');

    $html = view('livewire.events.version-scorecard-registration-timing', [
        'timing' => app(RegistrationTimingService::class)->forVersion($version),
    ])->render();

    expect($html)->toContain('When students were registered')
        ->toContain('Between the deadlines')
        ->toContain('1 (100%)')
        ->toContain('Monday, November 2');
});
