<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

use function Pest\Laravel\get;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ── Helper ────────────────────────────────────────────────────────────────────

function fakeSocialUser(string $id, string $name, string $email, string $token = 'tok'): SocialiteUser
{
    return (new SocialiteUser)->map([
        'id'    => $id,
        'name'  => $name,
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

// ── Auto-registration ─────────────────────────────────────────────────────────

test('new user via google is auto-registered as Teacher and sent to profile completion', function () {
    $social = fakeSocialUser('google-uid-123', 'Jane Smith', 'jane@example.com');

    Socialite::shouldReceive('driver->user')->andReturn($social);

    get(route('social.callback', 'google'))
        ->assertRedirect(route('social.profile.complete'));

    $user = User::where('email', 'jane@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->hasRole('Teacher'))->toBeTrue();
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->first_name)->toBe('Jane');
    expect($user->last_name)->toBe('Smith');
    expect(Teacher::where('user_id', $user->id)->exists())->toBeTrue();

    $sa = SocialAccount::where('provider', 'google')
        ->where('provider_user_id', 'google-uid-123')
        ->first();
    expect($sa)->not->toBeNull();
    expect($sa->user_id)->toBe($user->id);
    expect(Auth::id())->toBe($user->id);
});

test('facebook callback auto-registers new user as Teacher', function () {
    $social = fakeSocialUser('fb-uid-111', 'Bob Jones', 'bob@example.com', 'fb-tok');

    Socialite::shouldReceive('driver->user')->andReturn($social);

    get(route('social.callback', 'facebook'))
        ->assertRedirect(route('social.profile.complete'));

    expect(User::where('email', 'bob@example.com')->exists())->toBeTrue();
    expect(SocialAccount::where('provider', 'facebook')->where('provider_user_id', 'fb-uid-111')->exists())->toBeTrue();
});

// ── Returning users ───────────────────────────────────────────────────────────

test('existing user by provider_user_id is logged in and token updated', function () {
    $user = User::factory()->create();
    SocialAccount::create([
        'user_id'          => $user->id,
        'provider'         => 'google',
        'provider_user_id' => 'google-uid-456',
        'provider_token'   => 'old-token',
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
        ->andThrow(new \Laravel\Socialite\Two\InvalidStateException);

    get(route('social.callback', 'google'))
        ->assertRedirect(route('login'));
});

test('general oauth exception redirects to login with error', function () {
    Socialite::shouldReceive('driver->user')
        ->andThrow(new \Exception('Provider unreachable'));

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
