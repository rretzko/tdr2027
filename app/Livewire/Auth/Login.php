<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Enums\LoginMethod;
use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Login extends Component
{
    public string $identifier = '';

    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureIsNotRateLimited();

        $user = $this->findUser();

        if (! $user || ! $user->password || ! Hash::check($this->password, $user->password)) {
            RateLimiter::hit($this->throttleKey(), 60);

            throw ValidationException::withMessages([
                'identifier' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        Auth::login($user, $this->remember);
        LoginEvent::record($user, $this->identifierMethod());

        session()->regenerate();

        $this->redirectIntended(route('dashboard'), navigate: true);
    }

    protected function findUser(): ?User
    {
        if (str_contains($this->identifier, '@')) {
            return User::whereRaw('LOWER(email) = ?', [Str::lower(trim($this->identifier))])->first();
        }

        return User::where('cell_phone', preg_replace('/\D/', '', $this->identifier))->first();
    }

    protected function identifierMethod(): LoginMethod
    {
        return str_contains($this->identifier, '@') ? LoginMethod::Email : LoginMethod::CellPhone;
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'identifier' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->identifier).'|'.request()->ip());
    }
}
