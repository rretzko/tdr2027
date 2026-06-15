<?php

namespace App\Actions\Fortify;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::default()->mixedCase()->numbers()->uncompromised(), 'confirmed'];
    }

    /**
     * Get the validation rules used for live, as-you-type password feedback.
     *
     * Excludes the `uncompromised` check, which calls an external API and
     * isn't suitable to run on every keystroke.
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function passwordLiveRules(): array
    {
        return ['required', 'string', Password::default()->mixedCase()->numbers()];
    }
}
