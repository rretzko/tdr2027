<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Actions\Fortify\PasswordValidationRules;
use App\Models\Pronoun;
use App\Models\Teacher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.auth')]
class TeacherRegister extends Component
{
    use PasswordValidationRules;

    public string $honorific = '';

    public string $first_name = '';

    public string $middle_name = '';

    public string $last_name = '';

    public string $suffix_name = '';

    public string $pronoun_id = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $cell_phone = '';

    public function updatedPassword(): void
    {
        $this->validateOnly('password', ['password' => $this->passwordLiveRules()]);
    }

    public function updatedPasswordConfirmation(): void
    {
        $this->validateOnly('password_confirmation', [
            'password_confirmation' => ['same:password'],
        ], [
            'password_confirmation.same' => 'The passwords do not match.',
        ]);
    }

    public function register(): void
    {
        $this->validate([
            'cell_phone' => ['required', 'string', 'min:10', 'max:20', Rule::unique('users', 'cell_phone')],
        ]);

        $data = $this->only([
            'honorific', 'first_name', 'middle_name', 'last_name', 'suffix_name',
            'pronoun_id', 'email', 'password', 'password_confirmation',
        ]);
        $data['pronoun_id'] = (int) $data['pronoun_id'];

        $user = app(CreatesNewUsers::class)->create($data);

        $user->update(['cell_phone' => preg_replace('/\D/', '', $this->cell_phone)]);

        Teacher::create(['user_id' => $user->id]);

        $user->assignRole('Teacher');

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        Auth::login($user);

        session()->regenerate();

        $this->redirect(route('dashboard'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.auth.teacher-register', [
            'pronouns' => Pronoun::orderBy('sort_order')->get(),
        ]);
    }
}
