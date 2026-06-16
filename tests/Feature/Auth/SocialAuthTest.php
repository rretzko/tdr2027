<?php

declare(strict_types=1);

use App\Livewire\Auth\SocialPhoneCheck;
use App\Models\SocialAccount;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

// ── Helper ────────────────────────────────────────────────────────────────────

function fakeSocialUser(string $id, string $name, ?string $email, string $token = 'tok'): SocialiteUser
{
    return (new SocialiteUser)->map([
        'id' => $id,
        'name' => $name,
        'email' => $email,
        'token' => $token,
    ]);
}

// ── Provider validation ───────────────────────────────────────────────────────

test('redirect rejects non-allowed provider', function () {
    get(route('social.redirect', 'twitter'))
        ->assertRedirect(route('login'));
});

test('callback rejects non-allowed provider', function () {
    get(route('social.callback', 'twitter'))
        ->assertRedirect(route('login'));
});

// ── New user: callback stores payload and redirects to phone check ─────────────

test('new user via google callback stores payload and redirects to phone check', function () {
    $social = fakeSocialUser('google-uid-123', 'Jane Smith', 'jane@example.com');
    Socialite::shouldReceive('driver->user')->andReturn($social);

    get(route('social.callback', 'google'))
        ->assertRedirect(route('social.phone.check'));

    expect(User::where('email', 'jane@example.com')->exists())->toBeFalse();
    expect(session('social_oauth_payload.provider'))->toBe('google');
    expect(session('social_oauth_payload.email'))->toBe('jane@example.com');
    expect(session('social_oauth_payload.provider_user_id'))->toBe('google-uid-123');
});

test('new user via facebook callback stores payload and redirects to phone check', function () {
    $social = fakeSocialUser('fb-uid-111', 'Bob Jones', 'bob@example.com', 'fb-tok');
    Socialite::shouldReceive('driver->user')->andReturn($social);

    get(route('social.callback', 'facebook'))
        ->assertRedirect(route('social.phone.check'));

    expect(User::where('email', 'bob@example.com')->exists())->toBeFalse();
    expect(session('social_oauth_payload.provider'))->toBe('facebook');
});

// ── SocialPhoneCheck: new phone creates teacher ───────────────────────────────

test('phone check creates new teacher when phone is not registered', function () {
    session([
        'social_oauth_payload' => [
            'provider' => 'google',
            'provider_user_id' => 'google-uid-123',
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
            'avatar' => null,
            'token' => 'tok',
            'refresh_token' => null,
        ],
    ]);

    Livewire::test(SocialPhoneCheck::class)
        ->set('cell_phone', '5551234567')
        ->call('save')
        ->assertRedirect(route('social.profile.complete'));

    $user = User::where('email', 'jane@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->cell_phone)->toBe('5551234567');
    expect($user->hasRole('Teacher'))->toBeTrue();
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->first_name)->toBe('Jane');
    expect($user->last_name)->toBe('Smith');
    expect(Teacher::where('user_id', $user->id)->exists())->toBeTrue();
    expect(SocialAccount::where('provider', 'google')->where('provider_user_id', 'google-uid-123')->exists())->toBeTrue();
    expect(Auth::id())->toBe($user->id);
    expect(session()->has('social_oauth_payload'))->toBeFalse();
});

// ── SocialPhoneCheck: existing phone links social account ─────────────────────

test('phone check links social account to existing user and goes to dashboard', function () {
    $user = User::factory()->create();
    $user->forceFill(['cell_phone' => '5551234567'])->save();

    session([
        'social_oauth_payload' => [
            'provider' => 'google',
            'provider_user_id' => 'google-uid-999',
            'name' => 'Some Name',
            'email' => 'different@example.com',
            'avatar' => null,
            'token' => 'tok',
            'refresh_token' => null,
        ],
    ]);

    Livewire::test(SocialPhoneCheck::class)
        ->set('cell_phone', '5551234567')
        ->call('save')
        ->assertRedirect(route('dashboard'));

    expect(SocialAccount::where('user_id', $user->id)->where('provider', 'google')->exists())->toBeTrue();
    expect(Auth::id())->toBe($user->id);
    // Only one user with this cell phone (no duplicate account created).
    expect(User::where('cell_phone', '5551234567')->count())->toBe(1);
    expect(session()->has('social_oauth_payload'))->toBeFalse();
});

// ── Returning users ───────────────────────────────────────────────────────────

test('existing user by provider_user_id is logged in and token updated', function () {
    $user = User::factory()->create();
    SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-uid-456',
        'provider_token' => 'old-token',
    ]);

    $social = fakeSocialUser('google-uid-456', 'Jane Smith', $user->email, 'new-token');
    Socialite::shouldReceive('driver->user')->andReturn($social);

    get(route('social.callback', 'google'))
        ->assertRedirect(route('dashboard'));

    expect(Auth::id())->toBe($user->id);
    expect(SocialAccount::where('provider_user_id', 'google-uid-456')->first()->provider_token)
        ->toBe('new-token');
});

test('existing user found by email gets social account linked', function () {
    $user = User::factory()->create(['email' => 'existing@example.com']);
    expect(SocialAccount::count())->toBe(0);

    $social = fakeSocialUser('google-uid-789', 'Existing User', 'existing@example.com');
    Socialite::shouldReceive('driver->user')->andReturn($social);

    get(route('social.callback', 'google'))
        ->assertRedirect(route('dashboard'));

    expect(SocialAccount::where('user_id', $user->id)->where('provider', 'google')->exists())->toBeTrue();
    expect(Auth::id())->toBe($user->id);
});

// ── Error handling ────────────────────────────────────────────────────────────

test('invalid oauth state redirects to login with error', function () {
    Socialite::shouldReceive('driver->user')
        ->andThrow(new InvalidStateException);

    get(route('social.callback', 'google'))
        ->assertRedirect(route('login'));
});

test('general oauth exception redirects to login with error', function () {
    Socialite::shouldReceive('driver->user')
        ->andThrow(new Exception('Provider unreachable'));

    get(route('social.callback', 'google'))
        ->assertRedirect(route('login'));
});

// ── UI presence ───────────────────────────────────────────────────────────────

test('login page shows social login buttons', function () {
    get(route('login'))
        ->assertOk()
        ->assertSee(route('social.redirect', 'google'))
        ->assertSee(route('social.redirect', 'facebook'));
});

test('teacher register page shows social login buttons', function () {
    get(route('tdr.register'))
        ->assertOk()
        ->assertSee(route('social.redirect', 'google'))
        ->assertSee(route('social.redirect', 'facebook'));
});

test('student register page does not show social login buttons', function () {
    get(route('sfdi.register'))
        ->assertOk()
        ->assertDontSee(route('social.redirect', 'google'))
        ->assertDontSee(route('social.redirect', 'facebook'));
});
