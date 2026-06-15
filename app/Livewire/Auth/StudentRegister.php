<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Actions\Fortify\PasswordValidationRules;
use App\Enums\PhoneType;
use App\Models\Phone;
use App\Models\Pronoun;
use App\Models\Student;
use App\Support\EmailVerifiabilityChecker;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.auth')]
class StudentRegister extends Component
{
    use PasswordValidationRules;

    public string $honorific = '';

    public string $first_name = '';

    public string $middle_name = '';

    public string $last_name = '';

    public string $suffix_name = '';

    public int $pronoun_id = 1;

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
        $generatedEmail = $this->email === '';

        $email = $generatedEmail ? Str::uuid().'@studentfolder.info' : $this->email;

        $user = app(CreatesNewUsers::class)->create([
            ...$this->only([
                'honorific', 'first_name', 'middle_name', 'last_name', 'suffix_name',
                'pronoun_id', 'password', 'password_confirmation',
            ]),
            'email' => $email,
        ]);

        if ($generatedEmail || EmailVerifiabilityChecker::isLikelyUnverifiable($email)) {
            $user->forceFill(['email_unverifiable' => true])->save();
        }

        Student::create(['user_id' => $user->id]);

        $user->assignRole('Student');

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        if ($this->cell_phone !== '') {
            Phone::create([
                'user_id' => $user->id,
                'type' => PhoneType::Cell,
                'raw_number' => $this->cell_phone,
            ]);
        }

        Auth::login($user);

        session()->regenerate();

        $this->redirect(route('dashboard'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.auth.student-register', [
            'pronouns' => Pronoun::orderBy('sort_order')->get(),
        ]);
    }
}
