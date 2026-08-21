<?php

declare(strict_types=1);

namespace App\Livewire\Feedback;

use App\Enums\FeedbackRequestType;
use App\Models\Feedback;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithFileUploads;

    public string $activeTab = 'report';

    public string $from_page = '';

    public string $request_type = 'bug';

    public string $request = '';

    public $newFile = null;

    public bool $is_private = false;

    public function mount(): void
    {
        $this->from_page = url()->previous();
    }

    public function submit(): void
    {
        $validated = $this->validate([
            'request_type' => ['required', 'string', 'in:'.implode(',', array_column(FeedbackRequestType::cases(), 'value'))],
            'request' => ['required', 'string', 'max:2000'],
            'newFile' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif,pdf,txt,log', 'max:10240'],
        ]);

        $filePath = $this->newFile?->store('feedback/'.Auth::id(), 's3');

        Feedback::create([
            'user_id' => Auth::id(),
            'from_page' => $this->from_page,
            'request_type' => $validated['request_type'],
            'request' => $validated['request'],
            'file_path' => $filePath,
            'is_private' => $this->is_private,
        ]);

        $this->reset('request_type', 'request', 'newFile', 'is_private');
        $this->request_type = FeedbackRequestType::Bug->value;

        Flux::toast(text: 'Feedback submitted. Thank you!', variant: 'success');
    }

    /**
     * @return Collection<int, Feedback>
     */
    public function history(): Collection
    {
        return Feedback::where('user_id', Auth::id())->where('is_private', false)->latest()->get();
    }

    public function render(): View
    {
        return view('livewire.feedback.index', [
            'history' => $this->activeTab === 'history' ? $this->history() : collect(),
        ]);
    }
}
