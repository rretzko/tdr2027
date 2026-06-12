<?php

declare(strict_types=1);

use App\Enums\PhoneType;
use App\Livewire\Auth\TeacherRegister;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
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
        ->set('email', 'jane@example.com')
        ->set('cell_phone', '5551234567')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('register')
        ->assertRedirect(route('dashboard'));

    $user = User::where('email', 'jane@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->hasRole('Teacher'))->toBeTrue();
    expect($user->pronoun_id)->toBe(1);
    expect(Teacher::where('user_id', $user->id)->exists())->toBeTrue();

    $phone = $user->phones->first();
    expect($phone->type)->toBe(PhoneType::Cell);
    expect($phone->raw_number)->toBe('5551234567');

    expect(Auth::id())->toBe($user->id);
});

test('cell phone is required', function () {
    Livewire::test(TeacherRegister::class)
        ->set('first_name', 'Jane')
        ->set('last_name', 'Smith')
        ->set('email', 'jane@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('register')
        ->assertHasErrors('cell_phone');
});

test('first name, last name, and email are required', function () {
    Livewire::test(TeacherRegister::class)
        ->set('cell_phone', '5551234567')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('register')
        ->assertHasErrors(['first_name', 'last_name', 'email']);
});

test('email must be unique', function () {
    User::factory()->create(['email' => 'duplicate@example.com']);

    Livewire::test(TeacherRegister::class)
        ->set('first_name', 'Jane')
        ->set('last_name', 'Smith')
        ->set('email', 'duplicate@example.com')
        ->set('cell_phone', '5551234567')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('register')
        ->assertHasErrors('email');
});
