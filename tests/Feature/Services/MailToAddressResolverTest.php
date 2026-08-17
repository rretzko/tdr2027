<?php

declare(strict_types=1);

use App\Models\County;
use App\Models\School;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionMailToAddress;
use App\Services\MailToAddressResolver;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('resolve falls back to the Version\'s single Registration Manager when no Co-Registration Manager claims the county', function () {
    $resolver = app(MailToAddressResolver::class);
    $founder = makeFounder();
    $version = Version::factory()->create();
    $county = County::factory()->create();
    $school = School::factory()->create(['county_id' => $county->id]);

    $registrationManager = User::factory()->create();
    app(VersionRoleAssignmentService::class)->assignRole($founder, $version, $registrationManager, 'Registration Manager');

    $address = VersionMailToAddress::factory()->create([
        'version_id' => $version->id,
        'user_id' => $registrationManager->id,
    ]);

    $resolved = $resolver->resolve($version, $school);

    expect($resolved?->id)->toBe($address->id);
});

test('resolve prefers the Co-Registration Manager assigned to the school\'s county', function () {
    $resolver = app(MailToAddressResolver::class);
    $founder = makeFounder();
    $version = Version::factory()->create();
    $county = County::factory()->create();
    $school = School::factory()->create(['county_id' => $county->id]);

    $registrationManager = User::factory()->create();
    app(VersionRoleAssignmentService::class)->assignRole($founder, $version, $registrationManager, 'Registration Manager');
    VersionMailToAddress::factory()->create(['version_id' => $version->id, 'user_id' => $registrationManager->id]);

    $version->counties()->create(['county_id' => $county->id]);
    $coManager = User::factory()->create();
    app(VersionRoleAssignmentService::class)->assignCoRegistrationManager($founder, $version, $coManager, [$county->id]);
    $coManagerAddress = VersionMailToAddress::factory()->create(['version_id' => $version->id, 'user_id' => $coManager->id]);

    $resolved = $resolver->resolve($version, $school);

    expect($resolved?->id)->toBe($coManagerAddress->id);
});

test('resolve returns null when the responsible manager has no address on file yet', function () {
    $resolver = app(MailToAddressResolver::class);
    $founder = makeFounder();
    $version = Version::factory()->create();
    $school = School::factory()->create();

    $registrationManager = User::factory()->create();
    app(VersionRoleAssignmentService::class)->assignRole($founder, $version, $registrationManager, 'Registration Manager');

    expect($resolver->resolve($version, $school))->toBeNull();
});

test('resolve returns null when the Version has no Registration Manager and no matching county assignment', function () {
    $resolver = app(MailToAddressResolver::class);
    $version = Version::factory()->create();
    $school = School::factory()->create();

    expect($resolver->resolve($version, $school))->toBeNull();
});

test('resolve ignores a Co-Registration Manager assigned to a different county', function () {
    $resolver = app(MailToAddressResolver::class);
    $founder = makeFounder();
    $version = Version::factory()->create();
    $schoolCounty = County::factory()->create();
    $otherCounty = County::factory()->create();
    $school = School::factory()->create(['county_id' => $schoolCounty->id]);

    $registrationManager = User::factory()->create();
    app(VersionRoleAssignmentService::class)->assignRole($founder, $version, $registrationManager, 'Registration Manager');
    $rmAddress = VersionMailToAddress::factory()->create(['version_id' => $version->id, 'user_id' => $registrationManager->id]);

    $version->counties()->create(['county_id' => $otherCounty->id]);
    $coManager = User::factory()->create();
    app(VersionRoleAssignmentService::class)->assignCoRegistrationManager($founder, $version, $coManager, [$otherCounty->id]);
    VersionMailToAddress::factory()->create(['version_id' => $version->id, 'user_id' => $coManager->id]);

    $resolved = $resolver->resolve($version, $school);

    expect($resolved?->id)->toBe($rmAddress->id);
});
