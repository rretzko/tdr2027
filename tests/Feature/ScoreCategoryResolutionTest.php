<?php

declare(strict_types=1);

use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a Version with no category overrides inherits the Event default rubric', function () {
    $version = Version::factory()->create();

    $eventCategory = ScoreCategory::create([
        'event_id' => $version->event_id,
        'version_id' => null,
        'description' => 'Scales',
        'order_by' => 1,
    ]);

    expect($version->availableScoreCategories()->pluck('id')->all())
        ->toBe([$eventCategory->id]);
});

test('a Version with its own category rows uses only its own set, not the Event default', function () {
    $version = Version::factory()->create();

    ScoreCategory::create([
        'event_id' => $version->event_id,
        'version_id' => null,
        'description' => 'Event Default Scales',
        'order_by' => 1,
    ]);

    $versionCategory = ScoreCategory::create([
        'event_id' => $version->event_id,
        'version_id' => $version->id,
        'description' => 'Version-Specific Scales',
        'order_by' => 1,
    ]);

    $resolved = $version->availableScoreCategories();

    expect($resolved)->toHaveCount(1);
    expect($resolved->first()->id)->toBe($versionCategory->id);
});

test('availableScoreCategories does not leak another Event default rubric', function () {
    $version = Version::factory()->create();
    $otherEventVersion = Version::factory()->create();

    ScoreCategory::create([
        'event_id' => $otherEventVersion->event_id,
        'version_id' => null,
        'description' => 'Other Event Scales',
        'order_by' => 1,
    ]);

    expect($version->availableScoreCategories())->toHaveCount(0);
});

test('availableScoreFactors resolves factors belonging to the resolved category set', function () {
    $version = Version::factory()->create();

    $eventCategory = ScoreCategory::create([
        'event_id' => $version->event_id,
        'version_id' => null,
        'description' => 'Scales',
        'order_by' => 1,
    ]);

    $factor = ScoreFactor::create([
        'event_id' => $version->event_id,
        'version_id' => null,
        'score_category_id' => $eventCategory->id,
        'description' => 'High Scale',
        'abbreviation' => 'HS',
        'best' => 5,
        'worst' => 1,
        'interval_by' => 1,
        'multiplier' => 1,
        'tolerance' => null,
        'order_by' => 1,
    ]);

    expect($version->availableScoreFactors()->pluck('id')->all())
        ->toBe([$factor->id]);
});

test('availableScoreFactors switches to Version-specific factors once the Version overrides categories', function () {
    $version = Version::factory()->create();

    $eventCategory = ScoreCategory::create([
        'event_id' => $version->event_id,
        'version_id' => null,
        'description' => 'Event Default Scales',
        'order_by' => 1,
    ]);

    ScoreFactor::create([
        'event_id' => $version->event_id,
        'version_id' => null,
        'score_category_id' => $eventCategory->id,
        'description' => 'Event High Scale',
        'abbreviation' => 'HS',
        'best' => 5,
        'worst' => 1,
        'interval_by' => 1,
        'multiplier' => 1,
        'tolerance' => null,
        'order_by' => 1,
    ]);

    $versionCategory = ScoreCategory::create([
        'event_id' => $version->event_id,
        'version_id' => $version->id,
        'description' => 'Version Scales',
        'order_by' => 1,
    ]);

    $versionFactor = ScoreFactor::create([
        'event_id' => $version->event_id,
        'version_id' => $version->id,
        'score_category_id' => $versionCategory->id,
        'description' => 'Version High Scale',
        'abbreviation' => 'HS',
        'best' => 5,
        'worst' => 1,
        'interval_by' => 1,
        'multiplier' => 1,
        'tolerance' => null,
        'order_by' => 1,
    ]);

    expect($version->availableScoreFactors()->pluck('id')->all())
        ->toBe([$versionFactor->id]);
});

test('score_category belongs to its Event and optional Version', function () {
    $version = Version::factory()->create();

    $category = ScoreCategory::create([
        'event_id' => $version->event_id,
        'version_id' => $version->id,
        'description' => 'Scales',
        'order_by' => 1,
    ]);

    expect($category->event->id)->toBe($version->event_id);
    expect($category->version->id)->toBe($version->id);
});

test('score_factor belongs to its score_category', function () {
    $version = Version::factory()->create();

    $category = ScoreCategory::create([
        'event_id' => $version->event_id,
        'version_id' => null,
        'description' => 'Scales',
        'order_by' => 1,
    ]);

    $factor = ScoreFactor::create([
        'event_id' => $version->event_id,
        'version_id' => null,
        'score_category_id' => $category->id,
        'description' => 'High Scale',
        'abbreviation' => 'HS',
        'best' => 5,
        'worst' => 1,
        'interval_by' => 1,
        'multiplier' => 1,
        'tolerance' => null,
        'order_by' => 1,
    ]);

    expect($factor->scoreCategory->id)->toBe($category->id);
    expect($category->scoreFactors->pluck('id')->all())->toBe([$factor->id]);
});
