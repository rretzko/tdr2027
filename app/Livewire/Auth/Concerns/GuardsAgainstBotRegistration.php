<?php

declare(strict_types=1);

namespace App\Livewire\Auth\Concerns;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

trait GuardsAgainstBotRegistration
{
    /**
     * Honeypot trap field, rendered off-screen. Real users never fill this in;
     * bots that autofill every field do. Filling it fails the submission silently
     * (no validation error) so scripted bots get no signal to adapt against.
     */
    public string $company = '';

    public ?int $formRenderedAt = null;

    public function mountGuardsAgainstBotRegistration(): void
    {
        $this->formRenderedAt = now()->timestamp;
    }

    protected function isSuspectedBot(): bool
    {
        if ($this->company !== '') {
            return true;
        }

        // Scripted submissions tend to post within milliseconds of loading the
        // form; a human needs at least a couple seconds to fill it out.
        return $this->formRenderedAt !== null && now()->timestamp - $this->formRenderedAt < 2;
    }

    protected function ensureRegistrationIsNotRateLimited(): void
    {
        $key = 'register|'.request()->ip();

        if (! RateLimiter::tooManyAttempts($key, 10)) {
            RateLimiter::hit($key, 3600);

            return;
        }

        throw ValidationException::withMessages([
            'email' => 'Too many registration attempts from this network. Please try again later.',
        ]);
    }
}
