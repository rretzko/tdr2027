<?php

declare(strict_types=1);

use App\Models\Version;
use App\Support\CandidateApplicationData;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('tokenDescriptions keys exactly match toTokenMap keys', function () {
    $version = Version::factory()->create();
    $data = CandidateApplicationData::placeholder($version);

    expect(array_keys(CandidateApplicationData::tokenDescriptions()))
        ->toEqualCanonicalizing(array_keys($data->toTokenMap()));
});

test('tokenDescriptions is sorted by token name', function () {
    $keys = array_keys(CandidateApplicationData::tokenDescriptions());

    $sorted = $keys;
    sort($sorted);

    expect($keys)->toEqual($sorted);
});
