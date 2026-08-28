<?php

declare(strict_types=1);

use App\Livewire\Auth\StudentRegister;
use App\Livewire\Auth\TeacherRegister;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('teacher registration is silently dropped when the honeypot field is filled', function () {
    Livewire::test(TeacherRegister::class)
        ->set('formRenderedAt', now()->subSeconds(3)->timestamp)
        ->set('company', 'Acme Bots')
        ->set('first_name', 'Jane')
        ->set('last_name', 'Smith')
        ->set('pronoun_id', '2')
        ->set('email', 'jane@example.com')
        ->set('cell_phone', '5551234567')
        ->set('password', 'Tdr-Zx9Quokka!')
        ->set('password_confirmation', 'Tdr-Zx9Quokka!')
        ->call('register')
        ->assertNoRedirect()
        ->assertHasNoErrors();

    expect(User::where('email', 'jane@example.com')->exists())->toBeFalse();
});

test('student registration is silently dropped when the honeypot field is filled', function () {
    Livewire::test(StudentRegister::class)
        ->set('formRenderedAt', now()->subSeconds(3)->timestamp)
        ->set('company', 'Acme Bots')
        ->set('first_name', 'Alex')
        ->set('last_name', 'Lee')
        ->set('email', 'alex@example.com')
        ->set('password', 'Sfdi-Zx9Quokka!')
        ->set('password_confirmation', 'Sfdi-Zx9Quokka!')
        ->call('register')
        ->assertNoRedirect()
        ->assertHasNoErrors();

    expect(User::where('email', 'alex@example.com')->exists())->toBeFalse();
});

test('registration submitted faster than a human is silently dropped', function () {
    Livewire::test(TeacherRegister::class)
        ->set('first_name', 'Jane')
        ->set('last_name', 'Smith')
        ->set('pronoun_id', '2')
        ->set('email', 'jane@example.com')
        ->set('cell_phone', '5551234567')
        ->set('password', 'Tdr-Zx9Quokka!')
        ->set('password_confirmation', 'Tdr-Zx9Quokka!')
        ->call('register')
        ->assertNoRedirect()
        ->assertHasNoErrors();

    expect(User::where('email', 'jane@example.com')->exists())->toBeFalse();
});

test('teacher registration is rate limited per IP after repeated attempts', function () {
    for ($i = 0; $i < 10; $i++) {
        Livewire::test(TeacherRegister::class)
            ->set('formRenderedAt', now()->subSeconds(3)->timestamp)
            ->call('register');
    }

    Livewire::test(TeacherRegister::class)
        ->set('formRenderedAt', now()->subSeconds(3)->timestamp)
        ->set('first_name', 'Jane')
        ->set('last_name', 'Smith')
        ->set('pronoun_id', '2')
        ->set('email', 'jane@example.com')
        ->set('cell_phone', '5551234567')
        ->set('password', 'Tdr-Zx9Quokka!')
        ->set('password_confirmation', 'Tdr-Zx9Quokka!')
        ->call('register')
        ->assertHasErrors('email');

    expect(User::where('email', 'jane@example.com')->exists())->toBeFalse();
});
