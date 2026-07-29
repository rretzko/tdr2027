<?php

declare(strict_types=1);

use App\Livewire\Events\VersionScoringRubric;
use App\Models\Event;
use App\Models\Organization;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\User;
use App\Models\Version;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeRubricVersion(): Version
{
    $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    return Version::factory()->create(['event_id' => $event->id]);
}

function makeRubricCategory(Version $version, string $description = 'Scales', int $orderBy = 1): ScoreCategory
{
    return ScoreCategory::create([
        'event_id' => $version->event_id,
        'version_id' => null,
        'description' => $description,
        'order_by' => $orderBy,
    ]);
}

function makeRubricFactor(ScoreCategory $category, string $description = 'High Scale', int $orderBy = 1): ScoreFactor
{
    return ScoreFactor::create([
        'event_id' => $category->event_id,
        'version_id' => $category->version_id,
        'score_category_id' => $category->id,
        'description' => $description,
        'abbreviation' => 'HS',
        'best' => 5,
        'worst' => 1,
        'interval_by' => 1,
        'multiplier' => 1,
        'tolerance' => null,
        'order_by' => $orderBy,
    ]);
}

// --- Authorization ---

test('mount aborts with 403 for a user with no version-scoped role on the Version', function () {
    $user = User::factory()->create();
    $version = makeRubricVersion();

    Livewire::actingAs($user)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount allows a user holding Event Manager on the Version', function () {
    $user = User::factory()->create();
    $version = makeRubricVersion();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->assertOk();
});

test('mount allows the Founder regardless of any role assignment', function () {
    $founder = makeFounder();
    $version = makeRubricVersion();

    Livewire::actingAs($founder)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->assertOk();
});

test('mount allows Registration Manager and Co-Registration Manager', function () {
    $version = makeRubricVersion();

    $registrationManager = User::factory()->create();
    grantVersionRole($registrationManager, $version, 'Registration Manager');

    $coRegistrationManager = User::factory()->create();
    grantVersionRole($coRegistrationManager, $version, 'Co-Registration Manager');

    Livewire::actingAs($registrationManager)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->assertOk();

    Livewire::actingAs($coRegistrationManager)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->assertOk();
});

test('mount aborts with 403 for an unrelated version-scoped role (e.g. Tab Room Manager)', function () {
    $user = User::factory()->create();
    $version = makeRubricVersion();
    grantVersionRole($user, $version, 'Tab Room Manager');

    Livewire::actingAs($user)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->assertStatus(403);
});

// --- Inherit vs customize mode ---

test('a Version with no override rows renders in inheriting mode', function () {
    $eventManager = User::factory()->create();
    $version = makeRubricVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    makeRubricCategory($version);

    Livewire::actingAs($eventManager)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->assertViewHas('isCustomized', false);
});

// --- Category CRUD ---

test('saveCategory creates an Event-default category when the Version is not customized', function () {
    $eventManager = User::factory()->create();
    $version = makeRubricVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');

    Livewire::actingAs($eventManager)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->set('categoryDescription', 'Scales')
        ->call('saveCategory')
        ->assertDispatched('toast-show', slots: ['text' => '"Scales" saved.']);

    $category = ScoreCategory::where('event_id', $version->event_id)->first();

    expect($category)->not->toBeNull();
    expect($category->version_id)->toBeNull();
    expect($category->order_by)->toBe(1);
});

test('saveCategory creates a Version-owned category when the Version is customized', function () {
    $eventManager = User::factory()->create();
    $version = makeRubricVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    makeRubricCategory($version);

    Livewire::actingAs($eventManager)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->call('customizeForVersion')
        ->set('categoryDescription', 'Solo')
        ->call('saveCategory');

    $category = ScoreCategory::where('description', 'Solo')->first();

    expect($category)->not->toBeNull();
    expect($category->version_id)->toBe($version->id);
});

test('editCategory prefills the form and save updates the row in place', function () {
    $eventManager = User::factory()->create();
    $version = makeRubricVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $category = makeRubricCategory($version, 'Scales');

    Livewire::actingAs($eventManager)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->call('editCategory', $category->id)
        ->assertSet('editingCategoryId', $category->id)
        ->assertSet('categoryDescription', 'Scales')
        ->set('categoryDescription', 'Scales (renamed)')
        ->call('saveCategory');

    expect(ScoreCategory::count())->toBe(1);
    expect($category->fresh()->description)->toBe('Scales (renamed)');
});

test('removeCategory deletes the category and cascades its factors', function () {
    $eventManager = User::factory()->create();
    $version = makeRubricVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $category = makeRubricCategory($version);
    $factor = makeRubricFactor($category);

    Livewire::actingAs($eventManager)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->call('removeCategory', $category->id)
        ->assertDispatched('toast-show', slots: ['text' => '"Scales" removed.']);

    expect(ScoreCategory::find($category->id))->toBeNull();
    expect(ScoreFactor::find($factor->id))->toBeNull();
});

test('editCategory 404s for a category outside the Version\'s currently active set', function () {
    $eventManager = User::factory()->create();
    $version = makeRubricVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    makeRubricCategory($version);

    $otherVersion = makeRubricVersion();
    $otherCategory = makeRubricCategory($otherVersion, 'Other Event Scales');

    expect(fn () => Livewire::actingAs($eventManager)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->call('editCategory', $otherCategory->id))
        ->toThrow(ModelNotFoundException::class);
});

// --- Factor CRUD ---

test('addFactor + saveFactor creates a factor inheriting event_id/version_id from its category', function () {
    $eventManager = User::factory()->create();
    $version = makeRubricVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $category = makeRubricCategory($version);

    Livewire::actingAs($eventManager)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->call('addFactor', $category->id)
        ->assertSet('factorCategoryId', $category->id)
        ->set('factorDescription', 'High Scale')
        ->set('factorAbbreviation', 'HS')
        ->set('factorBest', '5')
        ->set('factorWorst', '1')
        ->call('saveFactor')
        ->assertDispatched('toast-show', slots: ['text' => '"High Scale" saved.']);

    $factor = ScoreFactor::where('score_category_id', $category->id)->first();

    expect($factor)->not->toBeNull();
    expect($factor->event_id)->toBe($category->event_id);
    expect($factor->version_id)->toBe($category->version_id);
    expect($factor->order_by)->toBe(1);
    expect($factor->interval_by)->toBe(1);
    expect($factor->multiplier)->toBe(1);
});

test('editFactor prefills the form and save updates the row in place', function () {
    $eventManager = User::factory()->create();
    $version = makeRubricVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $category = makeRubricCategory($version);
    $factor = makeRubricFactor($category, 'High Scale');

    Livewire::actingAs($eventManager)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->call('editFactor', $factor->id)
        ->assertSet('editingFactorId', $factor->id)
        ->assertSet('factorDescription', 'High Scale')
        ->assertSet('factorAbbreviation', 'HS')
        ->set('factorDescription', 'High Scale (renamed)')
        ->call('saveFactor');

    expect(ScoreFactor::count())->toBe(1);
    expect($factor->fresh()->description)->toBe('High Scale (renamed)');
});

test('removeFactor deletes the factor', function () {
    $eventManager = User::factory()->create();
    $version = makeRubricVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $category = makeRubricCategory($version);
    $factor = makeRubricFactor($category);

    Livewire::actingAs($eventManager)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->call('removeFactor', $factor->id)
        ->assertDispatched('toast-show', slots: ['text' => '"High Scale" removed.']);

    expect(ScoreFactor::find($factor->id))->toBeNull();
});

// --- Ordering ---

test('saveCategoryOrder bulk-persists order_by from the numeric inputs', function () {
    $eventManager = User::factory()->create();
    $version = makeRubricVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $first = makeRubricCategory($version, 'A', 1);
    $second = makeRubricCategory($version, 'B', 2);

    Livewire::actingAs($eventManager)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->set("categoryOrderInputs.{$first->id}", 2)
        ->set("categoryOrderInputs.{$second->id}", 1)
        ->call('saveCategoryOrder')
        ->assertDispatched('toast-show', slots: ['text' => 'Category order saved.']);

    expect($first->fresh()->order_by)->toBe(2);
    expect($second->fresh()->order_by)->toBe(1);
});

test('saveFactorOrder bulk-persists order_by from the numeric inputs', function () {
    $eventManager = User::factory()->create();
    $version = makeRubricVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $category = makeRubricCategory($version);
    $first = makeRubricFactor($category, 'A', 1);
    $second = makeRubricFactor($category, 'B', 2);

    Livewire::actingAs($eventManager)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->set("factorOrderInputs.{$first->id}", 2)
        ->set("factorOrderInputs.{$second->id}", 1)
        ->call('saveFactorOrder')
        ->assertDispatched('toast-show', slots: ['text' => 'Factor order saved.']);

    expect($first->fresh()->order_by)->toBe(2);
    expect($second->fresh()->order_by)->toBe(1);
});

// --- Customize / revert ---

test('customizeForVersion duplicates the Event default categories and factors into Version-owned rows', function () {
    $eventManager = User::factory()->create();
    $version = makeRubricVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $category = makeRubricCategory($version, 'Scales');
    makeRubricFactor($category, 'High Scale');

    Livewire::actingAs($eventManager)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->call('customizeForVersion')
        ->assertDispatched('toast-show', slots: ['text' => 'Rubric customized for this Version.']);

    expect(ScoreCategory::where('version_id', $version->id)->count())->toBe(1);
    expect(ScoreFactor::where('version_id', $version->id)->count())->toBe(1);

    $versionCategory = ScoreCategory::where('version_id', $version->id)->first();
    expect($versionCategory->description)->toBe('Scales');
    expect($versionCategory->scoreFactors->first()->description)->toBe('High Scale');

    // The Event default is untouched.
    expect(ScoreCategory::whereNull('version_id')->count())->toBe(1);
});

test('editing after customizeForVersion no longer touches the Event default rows', function () {
    $eventManager = User::factory()->create();
    $version = makeRubricVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $defaultCategory = makeRubricCategory($version, 'Scales');

    $component = Livewire::actingAs($eventManager)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->call('customizeForVersion');

    $versionCategory = ScoreCategory::where('version_id', $version->id)->first();

    $component->call('editCategory', $versionCategory->id)
        ->set('categoryDescription', 'Scales (version-only)')
        ->call('saveCategory');

    expect($defaultCategory->fresh()->description)->toBe('Scales');
    expect($versionCategory->fresh()->description)->toBe('Scales (version-only)');
});

test('customizeForVersion aborts with 409 when the Version is already customized', function () {
    $eventManager = User::factory()->create();
    $version = makeRubricVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    makeRubricCategory($version);

    Livewire::actingAs($eventManager)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->call('customizeForVersion')
        ->call('customizeForVersion')
        ->assertStatus(409);
});

test('revertToEventDefault deletes the Version-owned rows and falls back to the Event default', function () {
    $eventManager = User::factory()->create();
    $version = makeRubricVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    makeRubricCategory($version, 'Scales');

    Livewire::actingAs($eventManager)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->call('customizeForVersion')
        ->call('revertToEventDefault')
        ->assertDispatched('toast-show', slots: ['text' => "Reverted to the Event's default rubric."])
        ->assertViewHas('isCustomized', false);

    expect(ScoreCategory::where('version_id', $version->id)->count())->toBe(0);
    expect(ScoreCategory::whereNull('version_id')->count())->toBe(1);
});

test('revertToEventDefault aborts with 409 when the Version is not customized', function () {
    $eventManager = User::factory()->create();
    $version = makeRubricVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    makeRubricCategory($version);

    Livewire::actingAs($eventManager)
        ->test(VersionScoringRubric::class, ['version' => $version])
        ->call('revertToEventDefault')
        ->assertStatus(409);
});
