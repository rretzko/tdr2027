<?php

declare(strict_types=1);

use App\Livewire\Events\Reports\Index;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('mount succeeds for Founder', function () {
    $founder = makeFounder();
    $version = Version::factory()->create();

    Livewire::actingAs($founder)
        ->test(Index::class, ['version' => $version])
        ->assertOk();
});

test('mount succeeds for Registration Manager held specifically on that Version', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Registration Manager');

    Livewire::actingAs($user)
        ->test(Index::class, ['version' => $version])
        ->assertOk();
});

test('mount succeeds for Co-Registration Manager held specifically on that Version', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Co-Registration Manager');

    Livewire::actingAs($user)
        ->test(Index::class, ['version' => $version])
        ->assertOk();
});

test('mount aborts with 403 for an unrelated version-scoped role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Tab Room Manager');

    Livewire::actingAs($user)
        ->test(Index::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount aborts with 403 for a user with no role at all', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class, ['version' => $version])
        ->assertStatus(403);
});
