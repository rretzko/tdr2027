<?php

declare(strict_types=1);

use App\Enums\PhoneType;
use App\Livewire\Auth\StudentRegister;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

test('student registration screen can be rendered', function () {
    get('/sfdi/register')->assertOk();
});

test('new students can register with an email and phone', function () {
    Livewire::test(StudentRegister::class)
        ->set('first_name', 'Alex')
        ->set('last_name', 'Lee')
        ->set('email', 'alex@example.com')
        ->set('cell_phone', '5551234567')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('register')
        ->assertRedirect(route('dashboard'));

    $user = User::where('email', 'alex@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->hasRole('Student'))->toBeTrue();
    expect($user->email_unverifiable)->toBeFalse();
    expect(Student::where('user_id', $user->id)->exists())->toBeTrue();

    $phone = $user->phones->first();
    expect($phone->type)->toBe(PhoneType::Cell);
    expect($phone->raw_number)->toBe('5551234567');

    expect(Auth::id())->toBe($user->id);
});

test('students can register without an email', function () {
    Livewire::test(StudentRegister::class)
        ->set('first_name', 'Alex')
        ->set('last_name', 'Lee')
        ->set('cell_phone', '5551234567')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('register')
        ->assertRedirect(route('dashboard'));

    $user = User::where('first_name', 'Alex')->where('last_name', 'Lee')->first();

    expect($user)->not->toBeNull();
    expect($user->email)->toEndWith('@studentfolder.info');
    expect($user->email_unverifiable)->toBeTrue();
});

test('students can register without a phone', function () {
    Livewire::test(StudentRegister::class)
        ->set('first_name', 'Alex')
        ->set('last_name', 'Lee')
        ->set('email', 'alex@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('register')
        ->assertRedirect(route('dashboard'));

    $user = User::where('email', 'alex@example.com')->first();

    expect($user->phones)->toBeEmpty();
});

test('first name, last name, and password are required', function () {
    Livewire::test(StudentRegister::class)
        ->call('register')
        ->assertHasErrors(['first_name', 'last_name', 'password']);
});
