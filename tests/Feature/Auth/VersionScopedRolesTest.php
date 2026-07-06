<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Version;
use App\Services\VersionRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('a role assigned under one Version is not visible under another Version or globally', function () {
    $service = app(VersionRoleService::class);
    $user = User::factory()->create();
    $versionA = Version::factory()->create();
    $versionB = Version::factory()->create();

    $service->activateVersion($versionA);
    $user->assignRole('Event Manager');

    $service->activateVersion($versionA);
    expect($user->fresh()->hasRole('Event Manager'))->toBeTrue();

    $service->activateVersion($versionB);
    expect($user->fresh()->hasRole('Event Manager'))->toBeFalse();

    $service->activateGlobal();
    expect($user->fresh()->hasRole('Event Manager'))->toBeFalse();
});

test('a globally-assigned role is not visible while a Version context is active', function () {
    $service = app(VersionRoleService::class);
    $user = User::factory()->create();
    $version = Version::factory()->create();

    $service->activateGlobal();
    $user->assignRole('Teacher');

    expect($user->fresh()->hasRole('Teacher'))->toBeTrue();

    $service->activateVersion($version);
    expect($user->fresh()->hasRole('Teacher'))->toBeFalse();

    $service->activateGlobal();
    expect($user->fresh()->hasRole('Teacher'))->toBeTrue();
});

test('withGlobal runs the callback under global context and restores the previous context afterward', function () {
    $service = app(VersionRoleService::class);
    $user = User::factory()->create();
    $version = Version::factory()->create();

    $service->activateGlobal();
    $user->assignRole('Teacher');

    $service->activateVersion($version);

    $sawTeacherRole = $service->withGlobal(fn () => $user->fresh()->hasRole('Teacher'));

    expect($sawTeacherRole)->toBeTrue();
    expect($service->currentVersionId())->toBe($version->id);
});

test('withVersion runs the callback under the given Version and restores the previous context afterward, including nested calls', function () {
    $service = app(VersionRoleService::class);
    $user = User::factory()->create();
    $versionA = Version::factory()->create();
    $versionB = Version::factory()->create();

    $service->activateVersion($versionA);
    $user->assignRole('Event Manager');

    $service->activateGlobal();

    $outerSawRole = $service->withVersion($versionA, function () use ($service, $versionA, $versionB, $user) {
        $sawInVersionA = $user->fresh()->hasRole('Event Manager');

        $innerSawRole = $service->withVersion($versionB, fn () => $user->fresh()->hasRole('Event Manager'));

        expect($innerSawRole)->toBeFalse();
        expect($service->currentVersionId())->toBe($versionA->id);

        return $sawInVersionA;
    });

    expect($outerSawRole)->toBeTrue();
    expect($service->currentVersionId())->toBeNull();
});

test('exactly one roles row exists per role name regardless of how many Versions use it', function () {
    $service = app(VersionRoleService::class);
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $versionA = Version::factory()->create();
    $versionB = Version::factory()->create();

    $service->activateVersion($versionA);
    $userA->assignRole('Event Manager');

    $service->activateVersion($versionB);
    $userB->assignRole('Event Manager');

    expect(Role::where('name', 'Event Manager')->count())->toBe(1);
});
