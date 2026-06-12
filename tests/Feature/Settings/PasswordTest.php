<?php

declare(strict_types=1);

use App\Livewire\Settings\Password;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('password page is displayed', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->get('/settings/password')
        ->assertOk();
});

test('password can be updated', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Password::class)
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('update')
        ->assertHasNoErrors();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update the password', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Password::class)
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('update')
        ->assertHasErrors('current_password');

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});
