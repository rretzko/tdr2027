<?php

declare(strict_types=1);

use App\Livewire\Events\VersionCoRegistrationManagers;
use App\Models\CoRegistrationManagerCounty;
use App\Models\County;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeCoRegVersion(): Version
{
    $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    return Version::factory()->create(['event_id' => $event->id]);
}

test('mount aborts with 403 for a user with no version-scoped role on the Version', function () {
    $user = User::factory()->create();
    $version = makeCoRegVersion();

    Livewire::actingAs($user)
        ->test(VersionCoRegistrationManagers::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount allows the Founder regardless of any role assignment', function () {
    $founder = makeFounder();
    $version = makeCoRegVersion();

    Livewire::actingAs($founder)
        ->test(VersionCoRegistrationManagers::class, ['version' => $version])
        ->assertOk();
});

test('mount allows a user holding Event Manager on the Version', function () {
    $user = User::factory()->create();
    $version = makeCoRegVersion();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionCoRegistrationManagers::class, ['version' => $version])
        ->assertOk();
});

test('mount allows a user holding Registration Manager on the Version', function () {
    $user = User::factory()->create();
    $version = makeCoRegVersion();
    grantVersionRole($user, $version, 'Registration Manager');

    Livewire::actingAs($user)
        ->test(VersionCoRegistrationManagers::class, ['version' => $version])
        ->assertOk();
});

test('mount aborts with 403 for a Co-Registration Manager (no further delegation)', function () {
    $user = User::factory()->create();
    $version = makeCoRegVersion();
    grantVersionRole($user, $version, 'Co-Registration Manager');

    Livewire::actingAs($user)
        ->test(VersionCoRegistrationManagers::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount aborts with 403 for an unrelated version-scoped role (e.g. Tab Room Manager)', function () {
    $user = User::factory()->create();
    $version = makeCoRegVersion();
    grantVersionRole($user, $version, 'Tab Room Manager');

    Livewire::actingAs($user)
        ->test(VersionCoRegistrationManagers::class, ['version' => $version])
        ->assertStatus(403);
});

test('save assigns the role and the selected counties to the target user', function () {
    $registrationManager = User::factory()->create();
    $version = makeCoRegVersion();
    grantVersionRole($registrationManager, $version, 'Registration Manager');

    $county = County::factory()->create();
    $version->counties()->create(['county_id' => $county->id]);

    $targetUser = User::factory()->create();

    Livewire::actingAs($registrationManager)
        ->test(VersionCoRegistrationManagers::class, ['version' => $version])
        ->call('selectUser', $targetUser->id)
        ->set('countyIds', [$county->id])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', slots: ['text' => "{$targetUser->name} assigned as Co-Registration Manager."]);

    expect(CoRegistrationManagerCounty::where('version_id', $version->id)->where('user_id', $targetUser->id)->pluck('county_id')->all())
        ->toBe([$county->id]);
});

test('save rejects a county already assigned to a different Co-Registration Manager on the Version', function () {
    $registrationManager = User::factory()->create();
    $version = makeCoRegVersion();
    grantVersionRole($registrationManager, $version, 'Registration Manager');

    $county = County::factory()->create();
    $version->counties()->create(['county_id' => $county->id]);

    $existingManager = User::factory()->create();
    app(VersionRoleAssignmentService::class)->assignCoRegistrationManager($registrationManager, $version, $existingManager, [$county->id]);

    $newTarget = User::factory()->create();

    Livewire::actingAs($registrationManager)
        ->test(VersionCoRegistrationManagers::class, ['version' => $version])
        ->call('selectUser', $newTarget->id)
        ->set('countyIds', [$county->id])
        ->call('save')
        ->assertHasErrors(['countyIds.0']);

    expect(CoRegistrationManagerCounty::where('version_id', $version->id)->where('user_id', $newTarget->id)->exists())->toBeFalse();
});

test('editCounties prefills the current county selection and still allows keeping the manager\'s own counties', function () {
    $registrationManager = User::factory()->create();
    $version = makeCoRegVersion();
    grantVersionRole($registrationManager, $version, 'Registration Manager');

    $countyA = County::factory()->create();
    $countyB = County::factory()->create();
    $version->counties()->create(['county_id' => $countyA->id]);
    $version->counties()->create(['county_id' => $countyB->id]);

    $manager = User::factory()->create();
    app(VersionRoleAssignmentService::class)->assignCoRegistrationManager($registrationManager, $version, $manager, [$countyA->id]);

    Livewire::actingAs($registrationManager)
        ->test(VersionCoRegistrationManagers::class, ['version' => $version])
        ->call('editCounties', $manager->id)
        ->assertSet('countyIds', [$countyA->id])
        ->set('countyIds', [$countyA->id, $countyB->id])
        ->call('save')
        ->assertHasNoErrors();

    expect(CoRegistrationManagerCounty::where('version_id', $version->id)->where('user_id', $manager->id)->pluck('county_id')->sort()->values()->all())
        ->toBe([$countyA->id, $countyB->id]);
});

test('remove revokes the role and deletes the county rows', function () {
    $registrationManager = User::factory()->create();
    $version = makeCoRegVersion();
    grantVersionRole($registrationManager, $version, 'Registration Manager');

    $county = County::factory()->create();
    $version->counties()->create(['county_id' => $county->id]);

    $manager = User::factory()->create();
    app(VersionRoleAssignmentService::class)->assignCoRegistrationManager($registrationManager, $version, $manager, [$county->id]);

    Livewire::actingAs($registrationManager)
        ->test(VersionCoRegistrationManagers::class, ['version' => $version])
        ->call('remove', $manager->id)
        ->assertDispatched('toast-show', slots: ['text' => "{$manager->name} removed as Co-Registration Manager."]);

    expect(CoRegistrationManagerCounty::where('version_id', $version->id)->where('user_id', $manager->id)->exists())->toBeFalse();
});
