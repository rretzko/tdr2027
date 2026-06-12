<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Password extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $saved = false;

    public function update(): void
    {
        $this->saved = false;

        app(UpdatesUserPasswords::class)->update(auth()->user(), $this->only([
            'current_password', 'password', 'password_confirmation',
        ]));

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->saved = true;
    }
}
