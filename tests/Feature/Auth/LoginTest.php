<?php

declare(strict_types=1);

use App\Enums\LoginMethod;
use App\Livewire\Auth\Login;
use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

test('login screen can be rendered', function () {
    get('/login')->assertOk();
});

test('users can authenticate using their cell phone on the login screen', function () {
    $user = User::factory()->create(['cell_phone' => '5551234567']);

    Livewire::test(Login::class)
        ->set('identifier', '5551234567')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect(route('dashboard'));

    expect(Auth::check())->toBeTrue();
    expect(Auth::id())->toBe($user->id);
    expect(LoginEvent::where('user_id', $user->id)->value('method'))->toBe(LoginMethod::CellPhone);
});

test('users can authenticate using their email on the login screen', function () {
    $user = User::factory()->create(['email' => 'jane@example.com']);

    Livewire::test(Login::class)
        ->set('identifier', 'jane@example.com')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect(route('dashboard'));

    expect(Auth::check())->toBeTrue();
    expect(Auth::id())->toBe($user->id);
    expect(LoginEvent::where('user_id', $user->id)->value('method'))->toBe(LoginMethod::Email);
});

test('email login is case insensitive', function () {
    $user = User::factory()->create(['email' => 'jane@example.com']);

    Livewire::test(Login::class)
        ->set('identifier', 'JANE@EXAMPLE.COM')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect(route('dashboard'));

    expect(Auth::id())->toBe($user->id);
});

test('users cannot authenticate with an invalid password', function () {
    User::factory()->create(['cell_phone' => '5551234567']);

    Livewire::test(Login::class)
        ->set('identifier', '5551234567')
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('identifier');

    expect(Auth::check())->toBeFalse();
});

test('social-only users without a password cannot authenticate via the password form', function () {
    User::factory()->create(['email' => 'social@example.com', 'password' => null]);

    Livewire::test(Login::class)
        ->set('identifier', 'social@example.com')
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('identifier');

    expect(Auth::check())->toBeFalse();
});
