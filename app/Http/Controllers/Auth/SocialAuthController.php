<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Two\User as OAuth2User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

class SocialAuthController extends Controller
{
    /** @var list<string> */
    private const ALLOWED_PROVIDERS = ['google', 'facebook'];

    public function redirect(string $provider): SymfonyRedirectResponse
    {
        if (! in_array($provider, self::ALLOWED_PROVIDERS, strict: true)) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Unsupported login provider.']);
        }

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        if (! in_array($provider, self::ALLOWED_PROVIDERS, strict: true)) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Unsupported login provider.']);
        }

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (InvalidStateException) {
            return redirect()->route('login')
                ->withErrors(['email' => 'The OAuth state was invalid. Please try again.']);
        } catch (Throwable) {
            return redirect()->route('login')
                ->withErrors(['email' => 'We could not complete sign-in with '.ucfirst($provider).'. Please try again.']);
        }

        return DB::transaction(function () use ($provider, $socialUser): RedirectResponse {
            return $this->handleSocialUser($provider, $socialUser);
        });
    }

    private function handleSocialUser(string $provider, SocialiteUser $socialUser): RedirectResponse
    {
        $socialAccount = SocialAccount::with('user')
            ->where('provider', $provider)
            ->where('provider_user_id', (string) $socialUser->getId())
            ->first();

        if ($socialAccount !== null) {
            $this->updateTokens($socialAccount, $socialUser);
            Auth::login($socialAccount->user, remember: true);
            session()->regenerate();

            return redirect()->intended(route('dashboard'));
        }

        if ($socialUser->getEmail() !== null) {
            $user = User::where('email', $socialUser->getEmail())->first();

            if ($user !== null) {
                $this->createSocialAccount($user, $provider, $socialUser);
                Auth::login($user, remember: true);
                session()->regenerate();

                return redirect()->intended(route('dashboard'));
            }
        }

        $user = $this->registerSocialTeacher($provider, $socialUser);
        Auth::login($user, remember: true);
        session()->regenerate();

        return redirect()->route('social.profile.complete');
    }

    private function registerSocialTeacher(string $provider, SocialiteUser $socialUser): User
    {
        $nameParts = $this->parseName((string) ($socialUser->getName() ?? ''));

        $user = User::create([
            'first_name' => $nameParts['first_name'],
            'last_name'  => $nameParts['last_name'],
            'email'      => $socialUser->getEmail(),
            'password'   => null,
            'pronoun_id' => 1,
        ]);

        $user->markEmailAsVerified();

        Teacher::create(['user_id' => $user->id]);
        $user->assignRole('Teacher');

        $this->createSocialAccount($user, $provider, $socialUser);

        return $user;
    }

    private function createSocialAccount(User $user, string $provider, SocialiteUser $socialUser): void
    {
        $token        = $socialUser instanceof OAuth2User ? $socialUser->token : null;
        $refreshToken = $socialUser instanceof OAuth2User ? $socialUser->refreshToken : null;

        SocialAccount::create([
            'user_id'                => $user->id,
            'provider'               => $provider,
            'provider_user_id'       => (string) $socialUser->getId(),
            'provider_token'         => $token,
            'provider_refresh_token' => $refreshToken,
            'provider_avatar'        => $socialUser->getAvatar(),
        ]);
    }

    private function updateTokens(SocialAccount $account, SocialiteUser $socialUser): void
    {
        $token        = $socialUser instanceof OAuth2User ? $socialUser->token : null;
        $refreshToken = $socialUser instanceof OAuth2User ? $socialUser->refreshToken : null;

        $account->update([
            'provider_token'         => $token,
            'provider_refresh_token' => $refreshToken,
            'provider_avatar'        => $socialUser->getAvatar(),
        ]);
    }

    /**
     * Naively split "First [Middle] Last" into first_name / last_name.
     * Social users correct their name on the profile-completion page.
     *
     * @return array{first_name: string, last_name: string}
     */
    private function parseName(string $fullName): array
    {
        $parts = array_values(array_filter(explode(' ', trim($fullName))));

        if (count($parts) === 0) {
            return ['first_name' => 'Unknown', 'last_name' => 'Unknown'];
        }

        $last  = array_pop($parts);
        $first = array_shift($parts) ?? $last;

        return ['first_name' => $first, 'last_name' => $last];
    }
}
