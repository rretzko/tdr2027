<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

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
    public string $cell_phone = '';

    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate([
            'cell_phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureIsNotRateLimited();

        $user = User::where('cell_phone', preg_replace('/\D/', '', $this->cell_phone))->first();

        if (! $user || ! Hash::check($this->password, $user->password)) {
            RateLimiter::hit($this->throttleKey(), 60);

            throw ValidationException::withMessages([
                'cell_phone' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        Auth::login($user, $this->remember);

        session()->regenerate();

        $this->redirectIntended(route('dashboard'), navigate: true);
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'cell_phone' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->cell_phone).'|'.request()->ip());
    }
}
