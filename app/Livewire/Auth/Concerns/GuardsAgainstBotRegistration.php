<?php

declare(strict_types=1);

namespace App\Livewire\Auth\Concerns;

use App\Support\BotNameSignature;
use App\Support\TurnstileVerifier;
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

    /** Set client-side by the <x-turnstile> widget's callback. */
    public string $turnstileToken = '';

    public function mountGuardsAgainstBotRegistration(): void
    {
        $this->formRenderedAt = now()->timestamp;
    }

    protected function isSuspectedBot(): bool
    {
        if ($this->company !== '') {
            return true;
        }

        if (BotNameSignature::matches($this->middle_name, $this->suffix_name)) {
            return true;
        }

        // Scripted submissions tend to post within milliseconds of loading the
        // form; a human needs at least a couple seconds to fill it out.
        return $this->formRenderedAt !== null && now()->timestamp - $this->formRenderedAt < 2;
    }

    /**
     * Unlike the silent checks above, a Turnstile failure shows an error —
     * real people can fail or time out the challenge and need to retry.
     */
    protected function ensureTurnstilePassed(string $action): void
    {
        $passed = app(TurnstileVerifier::class)->verify($this->turnstileToken, $action, request()->ip());

        // Tokens are single-use: pass or fail, any retry (including one after
        // a later validation error) needs a fresh token from the widget.
        $this->turnstileToken = '';
        $this->dispatch('turnstile-reset');

        if (! $passed) {
            throw ValidationException::withMessages([
                'turnstile' => 'We couldn\'t confirm you\'re a person. Please complete the check above and try again.',
            ]);
        }
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
