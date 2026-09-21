<?php

declare(strict_types=1);

use App\Models\Ensemble;
use App\Models\Event;
use App\Models\User;
use App\Models\Version;
use App\Models\VoicePart;
use App\Services\AuditionCapService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(fn () => actingAs(User::factory()->create()));

/**
 * Mirrors the NJ Elementary & Junior High All-State shape: an Elementary
 * Ensemble with its own exclusive voice parts, and a Junior High SATB/SSA
 * pair that shares Soprano/Alto — SATB and SSA must resolve to one audition
 * group (a director doesn't choose between them at registration time), while
 * Elementary stays a separate group.
 *
 * A closure, not a bare named function — Pest test-file functions are global
 * across the whole suite, so a second file declaring the same name would
 * fatal-error the entire run (see feedback_pest_shared_global_functions).
 */
$makeElementaryJrHighEvent = function (): array {
    $event = Event::factory()->create();

    $trebleI = VoicePart::factory()->create(['name' => 'Treble I']);
    $trebleII = VoicePart::factory()->create(['name' => 'Treble II']);
    $trebleIII = VoicePart::factory()->create(['name' => 'Treble III']);
    $soprano = VoicePart::factory()->create(['name' => 'Soprano']);
    $alto = VoicePart::factory()->create(['name' => 'Alto']);
    $tenor = VoicePart::factory()->create(['name' => 'Tenor']);
    $bass = VoicePart::factory()->create(['name' => 'Bass']);

    $elementary = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Elementary Choir']);
    $elementary->voiceParts()->attach([$trebleI->id, $trebleII->id, $trebleIII->id]);

    $satb = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Junior High Choir (SATB)']);
    $satb->voiceParts()->attach([$soprano->id, $alto->id, $tenor->id, $bass->id]);

    $ssa = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Junior High Choir (SSA)']);
    $ssa->voiceParts()->attach([$soprano->id, $alto->id]);

    $version = Version::factory()->create(['event_id' => $event->id]);

    return compact('event', 'version', 'trebleI', 'trebleII', 'trebleIII', 'soprano', 'alto', 'tenor', 'bass', 'elementary', 'satb', 'ssa');
};

test('auditionGroupVoicePartIds merges SATB and SSA voice parts into one group via their shared Soprano/Alto', function () use ($makeElementaryJrHighEvent) {
    $data = $makeElementaryJrHighEvent();

    $group = (new AuditionCapService)->auditionGroupVoicePartIds($data['version'], $data['soprano']);

    expect($group->sort()->values()->all())->toBe(
        collect([$data['soprano']->id, $data['alto']->id, $data['tenor']->id, $data['bass']->id])->sort()->values()->all()
    );
});

test('auditionGroupVoicePartIds keeps Elementary voice parts in a separate group from Junior High', function () use ($makeElementaryJrHighEvent) {
    $data = $makeElementaryJrHighEvent();

    $group = (new AuditionCapService)->auditionGroupVoicePartIds($data['version'], $data['trebleI']);

    expect($group->sort()->values()->all())->toBe(
        collect([$data['trebleI']->id, $data['trebleII']->id, $data['trebleIII']->id])->sort()->values()->all()
    );
});

test('auditionGroupEnsembleNames names every Ensemble reachable in the group, including SSA via the shared Soprano/Alto pool', function () use ($makeElementaryJrHighEvent) {
    $data = $makeElementaryJrHighEvent();

    $names = (new AuditionCapService)->auditionGroupEnsembleNames($data['version'], $data['tenor']);

    expect($names->sort()->values()->all())->toBe(['Junior High Choir (SATB)', 'Junior High Choir (SSA)']);
});
