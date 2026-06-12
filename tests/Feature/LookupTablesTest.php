<?php

declare(strict_types=1);

use App\Models\County;
use App\Models\Geostate;
use App\Models\Instrument;
use App\Models\Pronoun;
use App\Models\User;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('pronoun is mass-assignable for its fillable attributes', function () {
    $pronoun = Pronoun::create([
        'description' => 'xe/xem/xyr/xyrs/xemself',
        'intensive' => 'xemself',
        'personal' => 'xe',
        'possessive' => 'xyrs',
        'object' => 'xyrs',
        'sort_order' => 999,
    ]);

    expect($pronoun->exists)->toBeTrue();
    expect($pronoun->sort_order)->toBe(999);
});

test('pronoun has many users', function () {
    $pronoun = Pronoun::factory()->create();
    $user = User::factory()->create(['pronoun_id' => $pronoun->id]);

    expect($pronoun->users)->toHaveCount(1);
    expect($pronoun->users->first()->id)->toBe($user->id);
});

test('voice_part scopeOrdered orders by sort_order', function () {
    VoicePart::factory()->create(['name' => 'Z Part', 'sort_order' => 1000]);
    VoicePart::factory()->create(['name' => 'A Part', 'sort_order' => 0]);

    expect(VoicePart::ordered()->first()->name)->toBe('A Part');
});

test('instrument casts in_band and in_orchestra to booleans', function () {
    $instrument = Instrument::factory()->create(['in_band' => 1, 'in_orchestra' => 0]);

    expect($instrument->in_band)->toBeTrue();
    expect($instrument->in_orchestra)->toBeFalse();
});

test('instrument scopeOrdered orders by sort_order', function () {
    Instrument::factory()->create(['name' => 'Z Instrument', 'sort_order' => 1000]);
    Instrument::factory()->create(['name' => 'A Instrument', 'sort_order' => 0]);

    expect(Instrument::ordered()->first()->name)->toBe('A Instrument');
});

test('geostate has many counties', function () {
    $geostate = Geostate::factory()->create();

    County::factory()->create(['geostate_id' => $geostate->id]);
    County::factory()->create(['geostate_id' => $geostate->id]);

    expect($geostate->counties()->count())->toBe(2);
});

test('county belongs to a geostate', function () {
    $geostate = Geostate::factory()->create();
    $county = County::factory()->create(['geostate_id' => $geostate->id]);

    expect($county->geostate->id)->toBe($geostate->id);
});
