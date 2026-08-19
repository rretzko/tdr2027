<?php

declare(strict_types=1);

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\ResetPassword;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

test('forgot password screen can be rendered', function () {
    get('/forgot-password')->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    Livewire::test(ForgotPassword::class)
        ->set('email', $user->email)
        ->call('sendResetLink');

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

// --- School-email advisory (studentfolder-module.md §3/§9 item 7) ---

test('a school-pattern email address shows the advisory alongside the normal confirmation, and the reset link is still sent', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'jsmith@district.k12.state.us']);

    Livewire::test(ForgotPassword::class)
        ->set('email', $user->email)
        ->call('sendResetLink')
        ->assertSee('Check your email')
        ->assertSee('This looks like a school email address')
        ->assertSee('please see your teacher');

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

test('a commercial email address shows only the normal confirmation, no advisory', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'jsmith@gmail.com']);

    Livewire::test(ForgotPassword::class)
        ->set('email', $user->email)
        ->call('sendResetLink')
        ->assertSee('Check your email')
        ->assertDontSee('This looks like a school email address');

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

test('the advisory is heuristic, not existence-revealing — it shows for a school-pattern address even when no account matches it', function () {
    Notification::fake();

    Livewire::test(ForgotPassword::class)
        ->set('email', 'nobody@district.k12.state.us')
        ->call('sendResetLink')
        ->assertSee('This looks like a school email address');
});

test('reset password screen can be rendered', function () {
    $user = User::factory()->create();

    $token = Password::createToken($user);

    get("/reset-password/{$token}?email={$user->email}")->assertOk();
});

test('password can be reset with a valid token', function () {
    $user = User::factory()->create();

    $token = Password::createToken($user);

    Livewire::test(ResetPassword::class, ['token' => $token, 'email' => $user->email])
        ->set('password', 'New-Passw0rd!')
        ->set('password_confirmation', 'New-Passw0rd!')
        ->call('resetPassword')
        ->assertRedirect(route('login'));

    expect(Hash::check('New-Passw0rd!', $user->refresh()->password))->toBeTrue();
});
