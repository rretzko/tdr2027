<?php

declare(strict_types=1);

use App\Models\Version;
use App\Models\VersionObligation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('mergeTokens replaces each token with its own value, not a positionally-swapped one', function () {
    $version = Version::factory()->create([
        'short_name' => 'NJASC',
        'name' => 'New Jersey All-State Chorus',
    ]);

    $result = VersionObligation::mergeTokens(
        'Short: {{versionShortName}} — Full: {{versionName}}',
        $version,
    );

    expect($result)->toBe('Short: NJASC — Full: New Jersey All-State Chorus');
});
