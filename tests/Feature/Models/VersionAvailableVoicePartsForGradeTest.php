<?php

declare(strict_types=1);

use App\Models\Ensemble;
use App\Models\EnsembleGrade;
use App\Models\Event;
use App\Models\Version;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('availableVoicePartsForGrade excludes voice parts whose only Ensemble is grade-restricted to a different grade', function () {
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);

    $trebleI = VoicePart::factory()->create(['name' => 'Treble I', 'sort_order' => 1]);
    $elementary = Ensemble::factory()->create(['event_id' => $event->id]);
    $elementary->voiceParts()->attach($trebleI->id);
    EnsembleGrade::create(['ensemble_id' => $elementary->id, 'grade' => 4]);
    EnsembleGrade::create(['ensemble_id' => $elementary->id, 'grade' => 5]);
    EnsembleGrade::create(['ensemble_id' => $elementary->id, 'grade' => 6]);

    $soprano = VoicePart::factory()->create(['name' => 'Soprano', 'sort_order' => 2]);
    $juniorHigh = Ensemble::factory()->create(['event_id' => $event->id]);
    $juniorHigh->voiceParts()->attach($soprano->id);
    EnsembleGrade::create(['ensemble_id' => $juniorHigh->id, 'grade' => 7]);
    EnsembleGrade::create(['ensemble_id' => $juniorHigh->id, 'grade' => 8]);
    EnsembleGrade::create(['ensemble_id' => $juniorHigh->id, 'grade' => 9]);

    expect($version->availableVoicePartsForGrade(4)->pluck('id')->all())->toBe([$trebleI->id]);
    expect($version->availableVoicePartsForGrade(9)->pluck('id')->all())->toBe([$soprano->id]);
});

test('availableVoicePartsForGrade includes a voice part shared by two Ensembles if either admits the grade', function () {
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);

    $soprano = VoicePart::factory()->create(['name' => 'Soprano']);

    $satb = Ensemble::factory()->create(['event_id' => $event->id]);
    $satb->voiceParts()->attach($soprano->id);
    EnsembleGrade::create(['ensemble_id' => $satb->id, 'grade' => 9]);

    $ssa = Ensemble::factory()->create(['event_id' => $event->id]);
    $ssa->voiceParts()->attach($soprano->id);
    EnsembleGrade::create(['ensemble_id' => $ssa->id, 'grade' => 7]);

    expect($version->availableVoicePartsForGrade(9)->pluck('id')->all())->toBe([$soprano->id]);
    expect($version->availableVoicePartsForGrade(7)->pluck('id')->all())->toBe([$soprano->id]);
    expect($version->availableVoicePartsForGrade(4)->pluck('id')->all())->toBe([]);
});

test('availableVoicePartsForGrade treats an Ensemble with no EnsembleGrade rows as unrestricted', function () {
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);

    $alto = VoicePart::factory()->create(['name' => 'Alto']);
    $ensemble = Ensemble::factory()->create(['event_id' => $event->id]);
    $ensemble->voiceParts()->attach($alto->id);
    // No EnsembleGrade rows at all.

    expect($version->availableVoicePartsForGrade(4)->pluck('id')->all())->toBe([$alto->id]);
    expect($version->availableVoicePartsForGrade(12)->pluck('id')->all())->toBe([$alto->id]);
});

test('availableVoicePartsForGrade falls back to every voice part when grade is null', function () {
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);

    $trebleI = VoicePart::factory()->create(['name' => 'Treble I', 'sort_order' => 1]);
    $elementary = Ensemble::factory()->create(['event_id' => $event->id]);
    $elementary->voiceParts()->attach($trebleI->id);
    EnsembleGrade::create(['ensemble_id' => $elementary->id, 'grade' => 4]);

    expect($version->availableVoicePartsForGrade(null)->pluck('id')->all())->toBe(
        $version->availableVoiceParts()->pluck('id')->all()
    );
});
