<?php

declare(strict_types=1);

namespace App\Livewire\Founder;

use App\Enums\FeedbackStatus;
use App\Models\Feedback;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Issues extends Component
{
    #[Url]
    public string $typeFilter = '';

    #[Url]
    public string $statusFilter = '';

    public function updateStatus(int $id, string $status): void
    {
        $feedback = Feedback::findOrFail($id);
        $feedback->update(['status' => $status]);

        Flux::toast(text: 'Issue status updated.', variant: 'success');
    }

    /**
     * @return Collection<int, Feedback>
     */
    public function issues(): Collection
    {
        return Feedback::with('user')
            ->when($this->typeFilter !== '', fn ($query) => $query->where('request_type', $this->typeFilter))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->latest()
            ->get();
    }

    public function render(): View
    {
        return view('livewire.founder.issues', [
            'issues' => $this->issues(),
            'statuses' => FeedbackStatus::cases(),
        ]);
    }
}
