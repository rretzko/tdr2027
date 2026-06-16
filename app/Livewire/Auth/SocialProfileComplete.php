<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Actions\Fortify\PasswordValidationRules;
use App\Models\Pronoun;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.auth')]
class SocialProfileComplete extends Component
{
    use PasswordValidationRules;

    public string $honorific = '';

    public string $pronoun_id = '';

    public string $first_name = '';

    public string $middle_name = '';

    public string $last_name = '';

    public string $suffix_name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $user = Auth::user();

        if ($user->pronoun_id !== null) {
            $this->redirect(route('dashboard'), navigate: true);

            return;
        }

        $this->honorific = $user->honorific ?? '';
        $this->pronoun_id = '';
        $this->first_name = $user->first_name;
        $this->middle_name = $user->middle_name ?? '';
        $this->last_name = $user->last_name;
        $this->suffix_name = $user->suffix_name ?? '';
        $this->email = $user->email ?? '';
    }

    public function save(): void
    {
        $passwordRules = filled($this->password)
            ? ['string', Password::default()->mixedCase()->numbers()->uncompromised(), 'confirmed']
            : [];

        $user = Auth::user();

        $this->validate([
            'honorific' => ['nullable', 'string', 'max:50'],
            'pronoun_id' => ['required', 'integer', Rule::exists(Pronoun::class, 'id')],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix_name' => ['nullable', 'string', 'max:50'],
            'email' => ['required', 'string', 'email:rfc,filter', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => array_merge(['nullable'], $passwordRules),
        ]);

        $emailChanged = $this->email !== $user->email;

        $user->update([
            'honorific' => $this->honorific ?: null,
            'pronoun_id' => (int) $this->pronoun_id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name ?: null,
            'last_name' => $this->last_name,
            'suffix_name' => $this->suffix_name ?: null,
            'email' => $this->email,
        ]);

        if ($emailChanged) {
            $user->forceFill(['email_verified_at' => null])->save();
            $user->sendEmailVerificationNotification();
        }

        if (filled($this->password)) {
            $user->update(['password' => Hash::make($this->password)]);
        }

        $this->redirect(route('dashboard'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.auth.social-profile-complete', [
            'pronouns' => Pronoun::orderBy('sort_order')->get(),
        ]);
    }
}
