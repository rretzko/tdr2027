<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Actions\Fortify\PasswordValidationRules;
use App\Enums\PhoneType;
use App\Models\Phone;
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

    public int $pronoun_id = 1;

    public string $first_name = '';

    public string $middle_name = '';

    public string $last_name = '';

    public string $suffix_name = '';

    public string $cell_phone = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $user = Auth::user();

        if ($user->phones()->where('type', PhoneType::Cell->value)->exists()) {
            $this->redirect(route('dashboard'), navigate: true);

            return;
        }

        $this->honorific  = $user->honorific ?? '';
        $this->pronoun_id = $user->pronoun_id;
        $this->first_name = $user->first_name;
        $this->middle_name = $user->middle_name ?? '';
        $this->last_name  = $user->last_name;
        $this->suffix_name = $user->suffix_name ?? '';
    }

    public function save(): void
    {
        $passwordRules = filled($this->password)
            ? ['string', Password::default()->mixedCase()->numbers()->uncompromised(), 'confirmed']
            : [];

        $this->validate([
            'honorific'    => ['nullable', 'string', 'max:50'],
            'pronoun_id'   => ['required', 'integer', Rule::exists(Pronoun::class, 'id')],
            'first_name'   => ['required', 'string', 'max:255'],
            'middle_name'  => ['nullable', 'string', 'max:255'],
            'last_name'    => ['required', 'string', 'max:255'],
            'suffix_name'  => ['nullable', 'string', 'max:50'],
            'cell_phone'   => ['required', 'string', 'min:10', 'max:20'],
            'password'     => array_merge(['nullable'], $passwordRules),
        ]);

        $user = Auth::user();

        $user->update([
            'honorific'   => $this->honorific ?: null,
            'pronoun_id'  => $this->pronoun_id,
            'first_name'  => $this->first_name,
            'middle_name' => $this->middle_name ?: null,
            'last_name'   => $this->last_name,
            'suffix_name' => $this->suffix_name ?: null,
        ]);

        if (filled($this->password)) {
            $user->update(['password' => Hash::make($this->password)]);
        }

        Phone::create([
            'user_id'    => $user->id,
            'type'       => PhoneType::Cell,
            'raw_number' => preg_replace('/\D/', '', $this->cell_phone),
        ]);

        $this->redirect(route('dashboard'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.auth.social-profile-complete', [
            'pronouns' => Pronoun::orderBy('sort_order')->get(),
        ]);
    }
}
