<?php

declare(strict_types=1);

use App\Livewire\Auth\TeacherRegister;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

test('teacher registration screen can be rendered', function () {
    get('/tdr/register')->assertOk();
});

test('new teachers can register', function () {
    Livewire::test(TeacherRegister::class)
        ->set('first_name', 'Jane')
        ->set('last_name', 'Smith')
        ->set('pronoun_id', '2')
        ->set('email', 'jane@example.com')
        ->set('cell_phone', '5551234567')
        ->set('password', 'Tdr-Zx9Quokka!')
        ->set('password_confirmation', 'Tdr-Zx9Quokka!')
        ->call('register')
        ->assertRedirect(route('dashboard'));

    $user = User::where('email', 'jane@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->hasRole('Teacher'))->toBeTrue();
    expect($user->pronoun_id)->toBe(2);
    expect($user->cell_phone)->toBe('5551234567');
    expect(Teacher::where('user_id', $user->id)->exists())->toBeTrue();

    expect(Auth::id())->toBe($user->id);
});

test('cell phone is required', function () {
    Livewire::test(TeacherRegister::class)
        ->set('first_name', 'Jane')
        ->set('last_name', 'Smith')
        ->set('pronoun_id', '2')
        ->set('email', 'jane@example.com')
        ->set('password', 'Tdr-Zx9Quokka!')
        ->set('password_confirmation', 'Tdr-Zx9Quokka!')
        ->call('register')
        ->assertHasErrors('cell_phone');
});

test('cell phone must be unique', function () {
    User::factory()->create(['cell_phone' => '5551234567']);

    Livewire::test(TeacherRegister::class)
        ->set('first_name', 'Jane')
        ->set('last_name', 'Smith')
        ->set('pronoun_id', '2')
        ->set('email', 'jane@example.com')
        ->set('cell_phone', '5551234567')
        ->set('password', 'Tdr-Zx9Quokka!')
        ->set('password_confirmation', 'Tdr-Zx9Quokka!')
        ->call('register')
        ->assertHasErrors('cell_phone');
});

test('first name, last name, and email are required', function () {
    Livewire::test(TeacherRegister::class)
        ->set('cell_phone', '5551234567')
        ->set('pronoun_id', '2')
        ->set('password', 'Tdr-Zx9Quokka!')
        ->set('password_confirmation', 'Tdr-Zx9Quokka!')
        ->call('register')
        ->assertHasErrors(['first_name', 'last_name', 'email']);
});

test('email must be unique', function () {
    User::factory()->create(['email' => 'duplicate@example.com']);

    Livewire::test(TeacherRegister::class)
        ->set('first_name', 'Jane')
        ->set('last_name', 'Smith')
        ->set('pronoun_id', '2')
        ->set('email', 'duplicate@example.com')
        ->set('cell_phone', '5559876543')
        ->set('password', 'Tdr-Zx9Quokka!')
        ->set('password_confirmation', 'Tdr-Zx9Quokka!')
        ->call('register')
        ->assertHasErrors('email');
});

test('teachers receive a verification email and must verify before reaching the dashboard', function () {
    Notification::fake();

    Livewire::test(TeacherRegister::class)
        ->set('first_name', 'Jane')
        ->set('last_name', 'Smith')
        ->set('pronoun_id', '2')
        ->set('email', 'jane@example.com')
        ->set('cell_phone', '5551234567')
        ->set('password', 'Tdr-Zx9Quokka!')
        ->set('password_confirmation', 'Tdr-Zx9Quokka!')
        ->call('register')
        ->assertRedirect(route('dashboard'));

    $user = User::where('email', 'jane@example.com')->first();

    expect($user->email_unverifiable)->toBeFalse();
    expect($user->hasVerifiedEmail())->toBeFalse();

    Notification::assertSentTo($user, VerifyEmail::class);

    get(route('dashboard'))->assertRedirect(route('verification.notice'));
});
