<?php

declare(strict_types=1);

use App\Models\County;
use App\Models\Geostate;
use App\Models\Instrument;
use App\Models\Pronoun;
use App\Models\VoicePart;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('GeostateSeeder seeds 59 US states and territories', function () {
    expect(Geostate::count())->toBe(59);
    expect(Geostate::find(37)->abbr)->toBe('NJ');
});

test('CountySeeder seeds 22 New Jersey counties', function () {
    expect(County::where('geostate_id', 37)->count())->toBe(22);
});

test('PronounSeeder seeds 9 pronoun sets with id 1 as she/her', function () {
    expect(Pronoun::count())->toBe(9);
    expect(Pronoun::find(1)->description)->toBe('she/her/hers/herself');
});

test('VoicePartSeeder seeds 17 voice parts ordered by sort_order', function () {
    expect(VoicePart::count())->toBe(17);
    expect(VoicePart::ordered()->first()->name)->toBe('Descant');
});

test('InstrumentSeeder seeds 28 instruments ordered by sort_order', function () {
    expect(Instrument::count())->toBe(28);
    expect(Instrument::ordered()->first()->name)->toBe('Flute');
});

test('RolesSeeder creates all 11 roles on the web guard', function () {
    expect(Role::where('guard_name', 'web')->count())->toBe(11);
    expect(Role::where('name', 'Founder/Admin')->where('guard_name', 'web')->exists())->toBeTrue();
    expect(Role::where('name', 'Co-Registration Manager')->where('guard_name', 'web')->exists())->toBeTrue();
});

test('RolesSeeder is idempotent', function () {
    (new RolesSeeder)->run();

    expect(Role::where('guard_name', 'web')->count())->toBe(11);
});
