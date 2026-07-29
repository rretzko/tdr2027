<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class VersionScoringRubric extends Component
{
    public Version $version;

    // Category form state
    public ?int $editingCategoryId = null;

    public string $categoryDescription = '';

    // Factor form state
    public ?int $editingFactorId = null;

    public ?int $factorCategoryId = null;

    public string $factorDescription = '';

    public string $factorAbbreviation = '';

    public string $factorBest = '';

    public string $factorWorst = '';

    public string $factorIntervalBy = '1';

    public string $factorMultiplier = '1';

    public string $factorTolerance = '';

    /** @var array<int, int> */
    public array $categoryOrderInputs = [];

    /** @var array<int, int> */
    public array $factorOrderInputs = [];

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageAuditionEnvironment(Auth::user(), $version), 403);

        $this->version = $version;
    }

    public function isCustomized(): bool
    {
        return $this->version->scoreCategories()->exists();
    }

    public function addCategory(): void
    {
        $this->editingCategoryId = null;
        $this->categoryDescription = '';
        $this->resetErrorBag();
    }

    public function editCategory(int $id): void
    {
        $category = $this->activeCategory($id);

        $this->editingCategoryId = $category->id;
        $this->categoryDescription = $category->description;
        $this->resetErrorBag();
    }

    public function saveCategory(VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageAuditionEnvironment(Auth::user(), $this->version), 403);

        $validated = $this->validate([
            'categoryDescription' => ['required', 'string', 'max:100'],
        ]);

        if ($this->editingCategoryId === null) {
            $isCustomized = $this->isCustomized();

            ScoreCategory::create([
                'event_id' => $this->version->event_id,
                'version_id' => $isCustomized ? $this->version->id : null,
                'description' => $validated['categoryDescription'],
                'order_by' => ((int) ScoreCategory::query()
                    ->where('event_id', $this->version->event_id)
                    ->where('version_id', $isCustomized ? $this->version->id : null)
                    ->max('order_by')) + 1,
            ]);
        } else {
            $category = $this->activeCategory($this->editingCategoryId);
            $category->update(['description' => $validated['categoryDescription']]);
        }

        $this->editingCategoryId = null;
        $this->modal('category-form')->close();

        Flux::toast(text: "\"{$validated['categoryDescription']}\" saved.", variant: 'success');
    }

    public function removeCategory(int $id, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageAuditionEnvironment(Auth::user(), $this->version), 403);

        $category = $this->activeCategory($id);
        $description = $category->description;
        $category->delete();
        unset($this->categoryOrderInputs[$id]);

        Flux::toast(text: "\"{$description}\" removed.", variant: 'success');
    }

    public function saveCategoryOrder(VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageAuditionEnvironment(Auth::user(), $this->version), 403);

        $this->validate([
            'categoryOrderInputs' => ['array'],
            'categoryOrderInputs.*' => ['required', 'integer', 'min:1', 'max:255'],
        ]);

        $activeIds = $this->version->availableScoreCategories()->pluck('id')->all();

        foreach ($this->categoryOrderInputs as $id => $orderBy) {
            if (in_array($id, $activeIds, true)) {
                ScoreCategory::where('id', $id)->update(['order_by' => (int) $orderBy]);
            }
        }

        Flux::toast('Category order saved.');
    }

    public function addFactor(int $categoryId): void
    {
        $this->activeCategory($categoryId);

        $this->editingFactorId = null;
        $this->factorCategoryId = $categoryId;
        $this->factorDescription = '';
        $this->factorAbbreviation = '';
        $this->factorBest = '';
        $this->factorWorst = '';
        $this->factorIntervalBy = '1';
        $this->factorMultiplier = '1';
        $this->factorTolerance = '';
        $this->resetErrorBag();
    }

    public function editFactor(int $id): void
    {
        $factor = $this->activeFactor($id);

        $this->editingFactorId = $factor->id;
        $this->factorCategoryId = $factor->score_category_id;
        $this->factorDescription = $factor->description;
        $this->factorAbbreviation = $factor->abbreviation;
        $this->factorBest = (string) $factor->best;
        $this->factorWorst = (string) $factor->worst;
        $this->factorIntervalBy = (string) $factor->interval_by;
        $this->factorMultiplier = (string) $factor->multiplier;
        $this->factorTolerance = $factor->tolerance === null ? '' : (string) $factor->tolerance;
        $this->resetErrorBag();
    }

    public function saveFactor(VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageAuditionEnvironment(Auth::user(), $this->version), 403);

        $category = $this->activeCategory($this->factorCategoryId ?? 0);

        $validated = $this->validate([
            'factorDescription' => ['required', 'string', 'max:150'],
            'factorAbbreviation' => ['required', 'string', 'max:20'],
            'factorBest' => ['required', 'integer', 'min:0', 'max:255'],
            'factorWorst' => ['required', 'integer', 'min:0', 'max:255'],
            'factorIntervalBy' => ['required', 'integer', 'min:1', 'max:255'],
            'factorMultiplier' => ['required', 'integer', 'min:1', 'max:255'],
            'factorTolerance' => ['nullable', 'integer', 'min:0', 'max:255'],
        ]);

        $data = [
            'description' => $validated['factorDescription'],
            'abbreviation' => $validated['factorAbbreviation'],
            'best' => (int) $validated['factorBest'],
            'worst' => (int) $validated['factorWorst'],
            'interval_by' => (int) $validated['factorIntervalBy'],
            'multiplier' => (int) $validated['factorMultiplier'],
            'tolerance' => $validated['factorTolerance'] !== null && $validated['factorTolerance'] !== '' ? (int) $validated['factorTolerance'] : null,
        ];

        if ($this->editingFactorId === null) {
            ScoreFactor::create([
                'event_id' => $category->event_id,
                'version_id' => $category->version_id,
                'score_category_id' => $category->id,
                ...$data,
                'order_by' => ((int) ScoreFactor::where('score_category_id', $category->id)->max('order_by')) + 1,
            ]);
        } else {
            $this->activeFactor($this->editingFactorId)->update($data);
        }

        $this->editingFactorId = null;
        $this->modal('factor-form')->close();

        Flux::toast(text: "\"{$validated['factorDescription']}\" saved.", variant: 'success');
    }

    public function removeFactor(int $id, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageAuditionEnvironment(Auth::user(), $this->version), 403);

        $factor = $this->activeFactor($id);
        $description = $factor->description;
        $factor->delete();
        unset($this->factorOrderInputs[$id]);

        Flux::toast(text: "\"{$description}\" removed.", variant: 'success');
    }

    public function saveFactorOrder(VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageAuditionEnvironment(Auth::user(), $this->version), 403);

        $this->validate([
            'factorOrderInputs' => ['array'],
            'factorOrderInputs.*' => ['required', 'integer', 'min:1', 'max:255'],
        ]);

        $activeIds = $this->version->availableScoreFactors()->pluck('id')->all();

        foreach ($this->factorOrderInputs as $id => $orderBy) {
            if (in_array($id, $activeIds, true)) {
                ScoreFactor::where('id', $id)->update(['order_by' => (int) $orderBy]);
            }
        }

        Flux::toast('Factor order saved.');
    }

    public function customizeForVersion(VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageAuditionEnvironment(Auth::user(), $this->version), 403);
        abort_if($this->isCustomized(), 409);

        DB::transaction(function (): void {
            $defaultCategories = ScoreCategory::query()
                ->where('event_id', $this->version->event_id)
                ->whereNull('version_id')
                ->with('scoreFactors')
                ->orderBy('order_by')
                ->get();

            foreach ($defaultCategories as $defaultCategory) {
                $newCategory = ScoreCategory::create([
                    'event_id' => $this->version->event_id,
                    'version_id' => $this->version->id,
                    'description' => $defaultCategory->description,
                    'order_by' => $defaultCategory->order_by,
                ]);

                foreach ($defaultCategory->scoreFactors as $defaultFactor) {
                    ScoreFactor::create([
                        'event_id' => $this->version->event_id,
                        'version_id' => $this->version->id,
                        'score_category_id' => $newCategory->id,
                        'description' => $defaultFactor->description,
                        'abbreviation' => $defaultFactor->abbreviation,
                        'best' => $defaultFactor->best,
                        'worst' => $defaultFactor->worst,
                        'interval_by' => $defaultFactor->interval_by,
                        'multiplier' => $defaultFactor->multiplier,
                        'tolerance' => $defaultFactor->tolerance,
                        'order_by' => $defaultFactor->order_by,
                    ]);
                }
            }
        });

        Flux::toast('Rubric customized for this Version.', variant: 'success');
    }

    public function revertToEventDefault(VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageAuditionEnvironment(Auth::user(), $this->version), 403);
        abort_unless($this->isCustomized(), 409);

        $this->version->scoreCategories()->delete();

        Flux::toast("Reverted to the Event's default rubric.", variant: 'success');
    }

    private function activeCategory(int $id): ScoreCategory
    {
        $activeIds = $this->version->availableScoreCategories()->pluck('id')->all();

        return ScoreCategory::whereIn('id', $activeIds)->findOrFail($id);
    }

    private function activeFactor(int $id): ScoreFactor
    {
        $activeIds = $this->version->availableScoreFactors()->pluck('id')->all();

        return ScoreFactor::whereIn('id', $activeIds)->findOrFail($id);
    }

    public function render(): View
    {
        $categories = $this->version->availableScoreCategories();
        $categories->each(fn (ScoreCategory $category) => $category->load('scoreFactors'));

        foreach ($categories as $category) {
            $this->categoryOrderInputs[$category->id] ??= $category->order_by;

            foreach ($category->scoreFactors as $factor) {
                $this->factorOrderInputs[$factor->id] ??= $factor->order_by;
            }
        }

        return view('livewire.events.version-scoring-rubric', [
            'categories' => $categories,
            'isCustomized' => $this->isCustomized(),
        ]);
    }
}
