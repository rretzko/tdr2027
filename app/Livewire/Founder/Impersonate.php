<?php

declare(strict_types=1);

namespace App\Livewire\Founder;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Impersonate extends Component
{
    public string $search = '';

    /**
     * @return Collection<int, User>
     */
    public function teachers(): Collection
    {
        $search = trim($this->search);

        if ($search === '') {
            return new Collection;
        }

        return User::query()
            ->ordered()
            ->whereKeyNot(Auth::id())
            ->whereHas('teacher')
            ->where(function ($query) use ($search) {
                $query->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            })
            ->with(['teacher.schools' => fn ($query) => $query->wherePivot('is_active', true)])
            ->limit(15)
            ->get();
    }

    public function impersonate(int $userId): void
    {
        $target = User::query()->whereHas('teacher')->findOrFail($userId);

        session()->put('impersonator_id', Auth::id());

        Auth::login($target);

        $this->redirect(route('dashboard'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.founder.impersonate', [
            'teachers' => $this->teachers(),
        ]);
    }
}
