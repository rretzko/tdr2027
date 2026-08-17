<?php

declare(strict_types=1);

use App\Livewire\Auth\SfdiLogin;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

test('the sfdi login screen can be rendered and shows StudentFolder.info branding, not social login or the teacher register link', function () {
    $response = get(route('sfdi.login'));

    $response->assertOk()
        ->assertSeeText('StudentFolder.info')
        ->assertSeeText('Log in to StudentFolder.info')
        ->assertDontSee('Continue with Google')
        ->assertDontSee('Continue with Facebook')
        ->assertDontSeeText('Register as a Teacher');
});

test('a student can log in with email and password', function () {
    $user = User::factory()->create(['email' => 'alex@example.com', 'password' => Hash::make('Sfdi-Zx9Quokka!')]);
    Student::factory()->create(['user_id' => $user->id]);

    Livewire::test(SfdiLogin::class)
        ->set('email', 'alex@example.com')
        ->set('password', 'Sfdi-Zx9Quokka!')
        ->call('login')
        ->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($user->id);
});

test('an incorrect password is rejected', function () {
    $user = User::factory()->create(['email' => 'alex@example.com', 'password' => Hash::make('Sfdi-Zx9Quokka!')]);
    Student::factory()->create(['user_id' => $user->id]);

    Livewire::test(SfdiLogin::class)
        ->set('email', 'alex@example.com')
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors(['email']);

    expect(auth()->check())->toBeFalse();
});
