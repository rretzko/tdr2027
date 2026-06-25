<?php

declare(strict_types=1);

namespace App\Livewire\Founder;

use App\Models\PageVisit;
use App\Models\TrackablePage;
use App\Support\FastPass;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class TrackablePages extends Component
{
    public ?int $editingId = null;

    public bool $isAdding = false;

    public string $edit_route_name = '';

    public string $edit_label = '';

    public function add(): void
    {
        $this->editingId = null;
        $this->isAdding = true;
        $this->edit_route_name = '';
        $this->edit_label = '';
        $this->resetErrorBag();
        $this->modal('trackable-page-modal')->show();
    }

    public function edit(int $id): void
    {
        $page = TrackablePage::findOrFail($id);

        $this->editingId = $id;
        $this->isAdding = false;
        $this->edit_route_name = $page->route_name;
        $this->edit_label = $page->label;
        $this->resetErrorBag();
        $this->modal('trackable-page-modal')->show();
    }

    public function saveAdd(): void
    {
        $this->validate([
            'edit_route_name' => [
                'required',
                'string',
                Rule::unique('trackable_pages', 'route_name'),
                Rule::in($this->namedGetRoutes()),
            ],
            'edit_label' => ['required', 'string', 'max:100'],
        ]);

        TrackablePage::create([
            'route_name' => $this->edit_route_name,
            'label' => $this->edit_label,
            'is_active' => true,
        ]);

        FastPass::clearCache();

        $this->isAdding = false;
        $this->modal('trackable-page-modal')->close();

        Flux::toast(text: "\"{$this->edit_label}\" added to trackable pages.", variant: 'success');
    }

    public function saveEdit(): void
    {
        $page = TrackablePage::findOrFail($this->editingId);

        $this->validate([
            'edit_label' => ['required', 'string', 'max:100'],
        ]);

        $page->update(['label' => $this->edit_label]);

        FastPass::clearCache();

        $pageVisit = PageVisit::where('route_name', $page->route_name)->first();
        if ($pageVisit !== null) {
            PageVisit::where('route_name', $page->route_name)->update(['label' => $this->edit_label]);
        }

        $this->editingId = null;
        $this->modal('trackable-page-modal')->close();

        Flux::toast(text: "\"{$page->route_name}\" label updated to \"{$this->edit_label}\".", variant: 'success');
    }

    public function toggleActive(int $id): void
    {
        $page = TrackablePage::findOrFail($id);
        $page->update(['is_active' => ! $page->is_active]);

        FastPass::clearCache();

        $state = $page->fresh()?->is_active ? 'enabled' : 'disabled';
        Flux::toast(text: "\"{$page->label}\" tracking {$state}.", variant: 'success');
    }

    public function delete(int $id): void
    {
        $page = TrackablePage::findOrFail($id);
        $label = $page->label;
        $routeName = $page->route_name;

        PageVisit::where('route_name', $routeName)->delete();
        $page->delete();

        FastPass::clearCache();

        Flux::toast(text: "\"{$label}\" removed from trackable pages and cleared from all Fast Pass histories.", variant: 'success');
    }

    /**
     * @return Collection<int, TrackablePage>
     */
    public function pages(): Collection
    {
        return TrackablePage::orderBy('route_name')->get();
    }

    /**
     * Named GET routes not already in the trackable_pages table.
     *
     * @return list<string>
     */
    public function availableRoutes(): array
    {
        $alreadyTracked = TrackablePage::pluck('route_name')->all();

        return collect(Route::getRoutes()->getRoutesByName())
            ->filter(fn ($route) => in_array('GET', $route->methods(), true))
            ->keys()
            ->reject(fn (string $name) => in_array($name, $alreadyTracked, true))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function namedGetRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutesByName())
            ->filter(fn ($route) => in_array('GET', $route->methods(), true))
            ->keys()
            ->values()
            ->all();
    }

    public function render(): View
    {
        return view('livewire.founder.trackable-pages', [
            'pages' => $this->pages(),
            'availableRoutes' => $this->availableRoutes(),
        ]);
    }
}
