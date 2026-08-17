<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The main Dashboard (resources/views/dashboard.blade.php) is a plain
 * Route::view page, not a Livewire component, so it has no wire:click of its
 * own to persist "the spotlight tour has been taken." This single-purpose
 * component embeds one hidden wire:click trigger for exactly that, mirroring
 * VersionDashboard::dismissOrientation() rather than introducing a new
 * plain-fetch-to-a-controller pattern into an otherwise Livewire-first app.
 */
class TourDismiss extends Component
{
    public function dismiss(): void
    {
        Auth::user()->update(['dismissed_dashboard_orientation_at' => now()]);
    }

    public function render(): View
    {
        return view('livewire.dashboard.tour-dismiss');
    }
}
