<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\CutoffStrategy;
use App\Livewire\Events\TabRoom\EnsembleCutoffs;
use App\Models\AuditionResult;
use App\Models\Candidate;
use App\Models\Event;
use App\Models\Organization;
use App\Models\RoomJudge;
use App\Models\Score;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionRoom;
use App\Services\AdjudicationService;
use App\Services\EnsembleCutoffService;
use Database\Seeders\SampleAdjudicationSeeder;
use Database\Seeders\SampleHonorChoirAssociationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

// Same scoping rule as SampleHonorChoirAssociationSeederTest: assert only on
// the sample Event's own rows, never global counts, so local CSV data
// doesn't affect the results.
uses(RefreshDatabase::class);

test('SampleAdjudicationSeeder gives both sample versions rooms, judges and a rubric covering every candidate', function () {
    Artisan::call('db:seed', ['--class' => SampleHonorChoirAssociationSeeder::class]);

    $event = Event::where('organization_id', Organization::where('name', 'Sample County Honor Choir Association')->value('id'))->first();
    $adjudication = app(AdjudicationService::class);

    foreach (['Fall 2025 Sample Cycle', 'Fall 2026 Sample Cycle'] as $versionName) {
        $version = Version::where('event_id', $event->id)->where('name', $versionName)->first();
        $rooms = VersionRoom::where('version_id', $version->id)->get();

        expect($rooms)->toHaveCount(2);

        foreach ($rooms as $room) {
            expect($adjudication->roomJudgesOrdered($room))->toHaveCount(3);
            expect($adjudication->factorCount($room))->toBe(6);
        }

        foreach (Candidate::where('version_id', $version->id)->get() as $candidate) {
            expect($adjudication->roomsForCandidate($candidate))->toHaveCount(1);
        }
    }

    expect(User::where('email', 'like', SampleAdjudicationSeeder::JUDGE_EMAIL_PATTERN)->count())->toBe(6);
});

test('closed-cycle scores rank every Accepted candidate ahead of every NotAccepted one, with AuditionResult matching', function () {
    Artisan::call('db:seed', ['--class' => SampleHonorChoirAssociationSeeder::class]);

    $version = Version::where('name', 'Fall 2025 Sample Cycle')->latest('id')->first();
    $candidates = Candidate::where('version_id', $version->id)->get()->groupBy(fn (Candidate $c): string => $c->getRawOriginal('status'));

    $totals = AuditionResult::where('version_id', $version->id)->pluck('total', 'candidate_id');

    // score_order is asc on the sample versions: lower total is better.
    $worstAccepted = $candidates[CandidateStatus::Accepted->value]->map(fn (Candidate $c): int => $totals[$c->id])->max();
    $bestNotAccepted = $candidates[CandidateStatus::NotAccepted->value]->map(fn (Candidate $c): int => $totals[$c->id])->min();
    expect($worstAccepted)->toBeLessThan($bestNotAccepted);

    foreach ($candidates[CandidateStatus::Accepted->value] as $candidate) {
        expect(Score::where('candidate_id', $candidate->id)->sum('score'))->toEqual($totals[$candidate->id]);
        expect(Score::where('candidate_id', $candidate->id)->count())->toBe(18); // 3 judges x 6 factors
    }

    foreach ($candidates[CandidateStatus::NoShow->value] as $candidate) {
        expect(Score::where('candidate_id', $candidate->id)->exists())->toBeFalse();
    }
});

test('active-cycle registered candidates cover fully scored, partial, out-of-tolerance and unscored states', function () {
    Artisan::call('db:seed', ['--class' => SampleHonorChoirAssociationSeeder::class]);

    $version = Version::where('name', 'Fall 2026 Sample Cycle')->latest('id')->first();
    $adjudication = app(AdjudicationService::class);

    $judgeCounts = Candidate::where('version_id', $version->id)
        ->where('status', CandidateStatus::Registered)
        ->get()
        ->map(fn (Candidate $c): int => Score::where('candidate_id', $c->id)->distinct()->count('judge_id'));

    expect($judgeCounts->contains(3))->toBeTrue();
    expect($judgeCounts->contains(1))->toBeTrue();
    expect($judgeCounts->contains(0))->toBeTrue();

    $outOfTolerance = Candidate::where('version_id', $version->id)
        ->where('status', CandidateStatus::Registered)
        ->get()
        ->filter(function (Candidate $c) use ($adjudication): bool {
            $room = $adjudication->roomsForCandidate($c)->first();

            return ! $adjudication->candidateTolerances($room, collect([$c])->pluck('id'))[$c->id];
        });

    expect($outOfTolerance)->not->toBeEmpty();
    expect(Score::where('version_id', $version->id)->whereNotIn('candidate_id', Candidate::where('version_id', $version->id)->where('status', CandidateStatus::Registered)->pluck('id'))->exists())->toBeFalse();
});

test('SampleAdjudicationSeeder is idempotent when run standalone on top of seeded data', function () {
    Artisan::call('db:seed', ['--class' => SampleHonorChoirAssociationSeeder::class]);
    Artisan::call('db:seed', ['--class' => SampleAdjudicationSeeder::class]);

    $versionIds = Version::whereIn('name', ['Fall 2025 Sample Cycle', 'Fall 2026 Sample Cycle'])->pluck('id');

    expect(VersionRoom::whereIn('version_id', $versionIds)->count())->toBe(4);
    expect(RoomJudge::whereIn('version_id', $versionIds)->count())->toBe(12);
    expect(User::where('email', 'like', SampleAdjudicationSeeder::JUDGE_EMAIL_PATTERN)->count())->toBe(6);
});

test('re-running SampleHonorChoirAssociationSeeder rebuilds adjudication without duplicate judge users', function () {
    Artisan::call('db:seed', ['--class' => SampleHonorChoirAssociationSeeder::class]);
    Artisan::call('db:seed', ['--class' => SampleHonorChoirAssociationSeeder::class]);

    $versionIds = Version::whereIn('name', ['Fall 2025 Sample Cycle', 'Fall 2026 Sample Cycle'])->pluck('id');

    expect($versionIds)->toHaveCount(2);
    expect(VersionRoom::whereIn('version_id', $versionIds)->count())->toBe(4);
    expect(User::where('email', 'like', SampleAdjudicationSeeder::JUDGE_EMAIL_PATTERN)->count())->toBe(6);
});

test('both sample versions are configured for Ensemble Cut-offs, and the page renders for the demo Event Manager', function () {
    Artisan::call('db:seed', ['--class' => SampleHonorChoirAssociationSeeder::class]);

    $eventManager = User::where('email', 'demo.eventmanager@sample-honorchoir.example')->first();

    foreach (['Fall 2025 Sample Cycle', 'Fall 2026 Sample Cycle'] as $versionName) {
        $version = Version::where('name', $versionName)->latest('id')->first();

        expect($version->getRawOriginal('cutoff_strategy'))->toBe(CutoffStrategy::AlternatingEnsembleAssignment->value);
        expect($version->ensembleOrder)->toHaveCount(3);

        // Every candidate's voice part is served by two ensembles: its
        // voice-specific choir and the Mixed choir.
        foreach (Candidate::where('version_id', $version->id)->with('voicePart')->get() as $candidate) {
            expect(app(EnsembleCutoffService::class)->eligibleEnsembles($version, $candidate->voicePart))->toHaveCount(2);
        }

        Livewire::actingAs($eventManager)
            ->test(EnsembleCutoffs::class, ['version' => $version])
            ->assertOk();
    }
});

test('closed-cycle cut-offs keep every seeded status and place accepted candidates only in ensembles that serve their voice part', function () {
    Artisan::call('db:seed', ['--class' => SampleHonorChoirAssociationSeeder::class]);

    $version = Version::where('name', 'Fall 2025 Sample Cycle')->latest('id')->first();
    $cutoffs = app(EnsembleCutoffService::class);

    $byStatus = Candidate::where('version_id', $version->id)->get()->countBy(fn (Candidate $c): string => $c->getRawOriginal('status'));
    expect($byStatus->all())->toEqual([
        CandidateStatus::Accepted->value => 16,
        CandidateStatus::NotAccepted->value => 4,
        CandidateStatus::NoShow->value => 4,
    ]);

    $accepted = Candidate::where('version_id', $version->id)->where('status', CandidateStatus::Accepted)->with('voicePart')->get();

    foreach ($accepted as $candidate) {
        expect($cutoffs->eligibleEnsembles($version, $candidate->voicePart)->pluck('id'))->toContain($candidate->accepted_ensemble_id);
    }
});
