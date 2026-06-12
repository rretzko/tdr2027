<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Enums\PhoneType;
use App\Models\Phone;
use App\Models\Pronoun;
use App\Models\Teacher;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.auth')]
class TeacherRegister extends Component
{
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

    public function register(): void
    {
        $this->validate([
            'cell_phone' => ['required', 'string'],
        ]);

        $user = app(CreatesNewUsers::class)->create($this->only([
            'honorific', 'first_name', 'middle_name', 'last_name', 'suffix_name',
            'pronoun_id', 'email', 'password', 'password_confirmation',
        ]));

        Teacher::create(['user_id' => $user->id]);

        $user->assignRole('Teacher');

        Phone::create([
            'user_id' => $user->id,
            'type' => PhoneType::Cell,
            'raw_number' => $this->cell_phone,
        ]);

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
