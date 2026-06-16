<?php

declare(strict_types=1);

use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

test('login screen can be rendered', function () {
    get('/login')->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create(['cell_phone' => '5551234567']);

    Livewire::test(Login::class)
        ->set('cell_phone', '5551234567')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect(route('dashboard'));

    expect(Auth::check())->toBeTrue();
    expect(Auth::id())->toBe($user->id);
});

test('users cannot authenticate with an invalid password', function () {
    User::factory()->create(['cell_phone' => '5551234567']);

    Livewire::test(Login::class)
        ->set('cell_phone', '5551234567')
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('cell_phone');

    expect(Auth::check())->toBeFalse();
});
