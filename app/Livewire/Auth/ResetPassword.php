<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Actions\Fortify\PasswordValidationRules;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use Livewire\Component;

class ResetPassword extends Component
{
    use PasswordValidationRules;

    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token, string $email = ''): void
    {
        $this->token = $token;
        $this->email = $email;
    }

    public function resetPassword(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
            'password' => $this->passwordRules(),
        ]);

        $status = Password::reset(
            $this->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) {
                app(ResetsUserPasswords::class)->reset($user, [
                    'password' => $this->password,
                    'password_confirmation' => $this->password_confirmation,
                ]);

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }

        $this->redirect(route('login'), navigate: true);
    }
}
