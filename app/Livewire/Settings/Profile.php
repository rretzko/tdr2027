<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\Pronoun;
use Illuminate\View\View;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Profile extends Component
{
    public string $honorific = '';

    public string $first_name = '';

    public string $middle_name = '';

    public string $last_name = '';

    public string $suffix_name = '';

    public int $pronoun_id = 1;

    public string $email = '';

    public bool $saved = false;

    public function mount(): void
    {
        $user = auth()->user();

        $this->honorific = (string) $user->honorific;
        $this->first_name = $user->first_name;
        $this->middle_name = (string) $user->middle_name;
        $this->last_name = $user->last_name;
        $this->suffix_name = (string) $user->suffix_name;
        $this->pronoun_id = $user->pronoun_id;
        $this->email = $user->email;
    }

    public function update(): void
    {
        $this->saved = false;

        app(UpdatesUserProfileInformation::class)->update(auth()->user(), $this->only([
            'honorific', 'first_name', 'middle_name', 'last_name', 'suffix_name', 'pronoun_id', 'email',
        ]));

        $this->saved = true;
    }

    public function render(): View
    {
        return view('livewire.settings.profile', [
            'pronouns' => Pronoun::orderBy('sort_order')->get(),
        ]);
    }
}
