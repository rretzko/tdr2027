<?php

declare(strict_types=1);

use App\Livewire\Auth\StudentRegister;
use App\Livewire\Auth\TeacherRegister;
use App\Models\User;
use App\Support\TurnstileVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->forgetInstance(TurnstileVerifier::class);

    config([
        'services.turnstile.secret_key' => 'test-secret',
        'services.turnstile.hostnames' => ['studentfolder.info', 'thedirectorsroom.com'],
    ]);
});

function fakeTurnstileSiteverify(array $body): void
{
    Http::fake(['challenges.cloudflare.com/*' => Http::response($body)]);
}

function runTurnstileVerify(string $token = 'token-abc', string $action = 'sfdi-register'): bool
{
    return app(TurnstileVerifier::class)->verify($token, $action, '203.0.113.5');
}

test('a successful response with matching action and hostname passes', function () {
    fakeTurnstileSiteverify(['success' => true, 'action' => 'sfdi-register', 'hostname' => 'studentfolder.info']);

    expect(runTurnstileVerify())->toBeTrue();

    Http::assertSent(fn ($request) => $request['secret'] === 'test-secret'
        && $request['response'] === 'token-abc'
        && $request['remoteip'] === '203.0.113.5');
});

test('an unsuccessful response fails', function () {
    fakeTurnstileSiteverify(['success' => false, 'error-codes' => ['timeout-or-duplicate']]);

    expect(runTurnstileVerify())->toBeFalse();
});

test('a token issued for a different action fails', function () {
    fakeTurnstileSiteverify(['success' => true, 'action' => 'tdr-register', 'hostname' => 'studentfolder.info']);

    expect(runTurnstileVerify(action: 'sfdi-register'))->toBeFalse();
});

test('a hostname outside the allowlist fails', function () {
    fakeTurnstileSiteverify(['success' => true, 'action' => 'sfdi-register', 'hostname' => 'evil.example']);

    expect(runTurnstileVerify())->toBeFalse();
});

test('a testing-key response skips the action check but still requires an allowed hostname', function () {
    fakeTurnstileSiteverify(['success' => true, 'hostname' => 'example.com', 'metadata' => ['result_with_testing_key' => true]]);

    expect(runTurnstileVerify())->toBeFalse();

    config(['services.turnstile.hostnames' => ['example.com']]);

    expect(runTurnstileVerify())->toBeTrue();
});

test('empty and oversized tokens fail without calling siteverify', function () {
    Http::fake();

    expect(runTurnstileVerify(token: ''))->toBeFalse()
        ->and(runTurnstileVerify(token: str_repeat('a', 2049)))->toBeFalse();

    Http::assertNothingSent();
});

test('a missing secret or empty hostname allowlist fails closed', function () {
    Http::fake();

    config(['services.turnstile.secret_key' => '']);
    expect(runTurnstileVerify())->toBeFalse();

    config(['services.turnstile.secret_key' => 'test-secret', 'services.turnstile.hostnames' => []]);
    expect(runTurnstileVerify())->toBeFalse();

    Http::assertNothingSent();
});

test('a network error fails closed', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(runTurnstileVerify())->toBeFalse();
});

test('a non-OK siteverify response fails closed', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response('oops', 500)]);

    expect(runTurnstileVerify())->toBeFalse();
});

test('student registration without a passing turnstile shows an error and creates no account', function () {
    fakeTurnstileSiteverify(['success' => false, 'error-codes' => ['invalid-input-response']]);

    Livewire::test(StudentRegister::class)
        ->set('formRenderedAt', now()->subSeconds(3)->timestamp)
        ->set('turnstileToken', 'bad-token')
        ->set('first_name', 'Alex')
        ->set('last_name', 'Lee')
        ->set('email', 'alex@example.com')
        ->set('password', 'Sfdi-Zx9Quokka!')
        ->set('password_confirmation', 'Sfdi-Zx9Quokka!')
        ->call('register')
        ->assertHasErrors('turnstile')
        ->assertDispatched('turnstile-reset')
        ->assertSet('turnstileToken', '')
        ->assertNoRedirect();

    expect(User::where('email', 'alex@example.com')->exists())->toBeFalse();
});

test('student registration with a passing turnstile creates the account', function () {
    fakeTurnstileSiteverify(['success' => true, 'action' => 'sfdi-register', 'hostname' => 'studentfolder.info']);

    Livewire::test(StudentRegister::class)
        ->set('formRenderedAt', now()->subSeconds(3)->timestamp)
        ->set('turnstileToken', 'good-token')
        ->set('first_name', 'Alex')
        ->set('last_name', 'Lee')
        ->set('email', 'alex@example.com')
        ->set('password', 'Sfdi-Zx9Quokka!')
        ->set('password_confirmation', 'Sfdi-Zx9Quokka!')
        ->call('register')
        ->assertRedirect();

    expect(User::where('email', 'alex@example.com')->exists())->toBeTrue();
});

test('teacher registration rejects a token issued to the student form', function () {
    fakeTurnstileSiteverify(['success' => true, 'action' => 'sfdi-register', 'hostname' => 'thedirectorsroom.com']);

    Livewire::test(TeacherRegister::class)
        ->set('formRenderedAt', now()->subSeconds(3)->timestamp)
        ->set('turnstileToken', 'student-form-token')
        ->set('first_name', 'Jane')
        ->set('last_name', 'Smith')
        ->set('pronoun_id', '2')
        ->set('email', 'jane@example.com')
        ->set('cell_phone', '5551234567')
        ->set('password', 'Tdr-Zx9Quokka!')
        ->set('password_confirmation', 'Tdr-Zx9Quokka!')
        ->call('register')
        ->assertHasErrors('turnstile');

    expect(User::where('email', 'jane@example.com')->exists())->toBeFalse();
});

test('a later validation error still resets the widget because the token was spent', function () {
    fakeTurnstileSiteverify(['success' => true, 'action' => 'tdr-register', 'hostname' => 'thedirectorsroom.com']);

    Livewire::test(TeacherRegister::class)
        ->set('formRenderedAt', now()->subSeconds(3)->timestamp)
        ->set('turnstileToken', 'good-token')
        ->set('first_name', 'Jane')
        ->call('register')
        ->assertHasErrors('cell_phone')
        ->assertDispatched('turnstile-reset');
});

test('both registration pages render the turnstile widget', function () {
    config(['services.turnstile.site_key' => '0x4AAAAAAFmvTDRqfAt--wSg']);

    get(route('sfdi.register'))->assertOk()->assertSee('sfdi-register')->assertSee('0x4AAAAAAFmvTDRqfAt--wSg');
    get(route('tdr.register'))->assertOk()->assertSee('tdr-register');
});
