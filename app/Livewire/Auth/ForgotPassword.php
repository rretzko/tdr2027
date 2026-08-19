<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Support\EmailVerifiabilityChecker;
use Illuminate\Support\Facades\Password;
use Livewire\Component;

class ForgotPassword extends Component
{
    public string $email = '';

    public bool $status = false;

    public bool $emailLikelyUnverifiable = false;

    /**
     * studentfolder-module.md §3 — a heuristic, not a certainty, so the
     * advisory renders alongside the normal "check your email" confirmation
     * (never instead of it): the reset email may still arrive despite the
     * domain matching a school-ish pattern. Reuses the same checker
     * StudentRegister::register() already applies at signup time rather than
     * maintaining a second, allowlist-based "is this a school email"
     * definition (§3's own flagged assumption).
     */
    public function sendResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        Password::sendResetLink($this->only('email'));

        $this->emailLikelyUnverifiable = EmailVerifiabilityChecker::isLikelyUnverifiable($this->email);
        $this->status = true;
    }
}
