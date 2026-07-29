<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.edit', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Scoring Rubric</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
        <div>
            <flux:heading size="xl">Scoring Rubric</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — score categories and factors used by Rooms</flux:text>
        </div>

        <flux:modal.trigger name="category-form">
            <flux:button size="sm" variant="primary" icon="plus" wire:click="addCategory">
                Add category
            </flux:button>
        </flux:modal.trigger>
    </div>

    @if ($isCustomized)
        <flux:callout variant="warning" icon="pencil-square" class="mb-4">
            <flux:callout.heading>This Version has its own rubric</flux:callout.heading>
            <flux:callout.text>Changes here apply only to {{ $version->name }}, separate from the Event's default rubric.</flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" variant="ghost" wire:click="revertToEventDefault" wire:confirm="Revert to the Event's default rubric? This Version's customized categories and factors will be permanently deleted.">
                    Revert to Event default
                </flux:button>
            </x-slot>
        </flux:callout>
    @else
        <flux:callout variant="info" icon="information-circle" class="mb-4">
            <flux:callout.heading>Editing the Event's default rubric</flux:callout.heading>
            <flux:callout.text>Changes here apply to every Version of {{ $version->event->name }} that uses the default rubric.</flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" variant="ghost" wire:click="customizeForVersion">
                    Customize for this Version
                </flux:button>
            </x-slot>
        </flux:callout>
    @endif

    @if ($categories->isEmpty())
        <flux:callout variant="info" icon="magnifying-glass">
            <flux:callout.text>No score categories yet. Add one above to start building the rubric.</flux:callout.text>
        </flux:callout>
    @else
        <div class="space-y-4">
            @foreach ($categories as $category)
                <flux:card size="sm">
                    <div class="flex items-start justify-between gap-3 mb-3">
                        <flux:heading size="base">{{ $category->description }}</flux:heading>
                        <div class="flex items-center gap-2 shrink-0">
                            <flux:input type="number" min="1" max="255" wire:model="categoryOrderInputs.{{ $category->id }}" size="sm" class="w-16" />
                            <flux:modal.trigger name="category-form">
                                <flux:button size="sm" variant="outline" wire:click="editCategory({{ $category->id }})">
                                    Edit
                                </flux:button>
                            </flux:modal.trigger>
                            <flux:button size="sm" variant="danger" wire:click="removeCategory({{ $category->id }})" wire:confirm="Remove &quot;{{ $category->description }}&quot;? Its factors will be removed too.">
                                Remove
                            </flux:button>
                        </div>
                    </div>

                    @if ($category->scoreFactors->isEmpty())
                        <flux:text size="sm" class="text-zinc-400 mb-2">No factors in this category yet.</flux:text>
                    @else
                        <div class="hidden md:block overflow-x-auto mb-2">
                            <flux:table>
                                <flux:table.columns>
                                    <flux:table.column>Factor</flux:table.column>
                                    <flux:table.column>Abbr.</flux:table.column>
                                    <flux:table.column>Best</flux:table.column>
                                    <flux:table.column>Worst</flux:table.column>
                                    <flux:table.column>Interval</flux:table.column>
                                    <flux:table.column>Multiplier</flux:table.column>
                                    <flux:table.column>Tolerance</flux:table.column>
                                    <flux:table.column>Order</flux:table.column>
                                    <flux:table.column></flux:table.column>
                                </flux:table.columns>
                                <flux:table.rows>
                                    @foreach ($category->scoreFactors as $factor)
                                        <flux:table.row :key="$factor->id">
                                            <flux:table.cell>{{ $factor->description }}</flux:table.cell>
                                            <flux:table.cell>{{ $factor->abbreviation }}</flux:table.cell>
                                            <flux:table.cell>{{ $factor->best }}</flux:table.cell>
                                            <flux:table.cell>{{ $factor->worst }}</flux:table.cell>
                                            <flux:table.cell>{{ $factor->interval_by }}</flux:table.cell>
                                            <flux:table.cell>{{ $factor->multiplier }}</flux:table.cell>
                                            <flux:table.cell>{{ $factor->tolerance ?? '—' }}</flux:table.cell>
                                            <flux:table.cell>
                                                <flux:input type="number" min="1" max="255" wire:model="factorOrderInputs.{{ $factor->id }}" size="sm" class="w-16" />
                                            </flux:table.cell>
                                            <flux:table.cell>
                                                <div class="flex items-center gap-2">
                                                    <flux:modal.trigger name="factor-form">
                                                        <flux:button size="sm" variant="outline" wire:click="editFactor({{ $factor->id }})">
                                                            Edit
                                                        </flux:button>
                                                    </flux:modal.trigger>
                                                    <flux:button size="sm" variant="danger" wire:click="removeFactor({{ $factor->id }})" wire:confirm="Remove &quot;{{ $factor->description }}&quot;?">
                                                        Remove
                                                    </flux:button>
                                                </div>
                                            </flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </div>

                        <div class="md:hidden space-y-2 mb-2">
                            @foreach ($category->scoreFactors as $factor)
                                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3">
                                    <div class="flex items-start justify-between gap-2 mb-1">
                                        <div class="font-medium">{{ $factor->description }} <span class="text-zinc-400">({{ $factor->abbreviation }})</span></div>
                                    </div>
                                    <flux:text size="sm" class="text-zinc-500 mb-2">
                                        Best {{ $factor->best }} · Worst {{ $factor->worst }} · Interval {{ $factor->interval_by }} · Multiplier {{ $factor->multiplier }} · Tolerance {{ $factor->tolerance ?? '—' }}
                                    </flux:text>
                                    <div class="flex items-center gap-2">
                                        <flux:input type="number" min="1" max="255" wire:model="factorOrderInputs.{{ $factor->id }}" size="sm" class="w-16" />
                                        <flux:spacer />
                                        <flux:modal.trigger name="factor-form">
                                            <flux:button size="sm" variant="outline" wire:click="editFactor({{ $factor->id }})">
                                                Edit
                                            </flux:button>
                                        </flux:modal.trigger>
                                        <flux:button size="sm" variant="danger" wire:click="removeFactor({{ $factor->id }})" wire:confirm="Remove &quot;{{ $factor->description }}&quot;?">
                                            Remove
                                        </flux:button>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <flux:button size="sm" variant="ghost" wire:click="saveFactorOrder">Save Factor Order</flux:button>
                    @endif

                    <div class="mt-2">
                        <flux:modal.trigger name="factor-form">
                            <flux:button size="sm" variant="outline" icon="plus" wire:click="addFactor({{ $category->id }})">
                                Add factor
                            </flux:button>
                        </flux:modal.trigger>
                    </div>
                </flux:card>
            @endforeach

            <flux:button size="sm" wire:click="saveCategoryOrder">Save Category Order</flux:button>
        </div>
    @endif

    <flux:modal name="category-form" class="md:w-[28rem]">
        <form wire:submit="saveCategory" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingCategoryId === null ? 'Add category' : 'Edit category' }}</flux:heading>
                <flux:subheading>{{ $version->name }}</flux:subheading>
            </div>

            <flux:input wire:model="categoryDescription" label="Description" placeholder="ex. Scales" autofocus />

            @if ($errors->any())
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>Please correct the errors above before saving.</flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">
                    {{ $editingCategoryId === null ? 'Add' : 'Save' }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="factor-form" class="md:w-[32rem]">
        <form wire:submit="saveFactor" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingFactorId === null ? 'Add factor' : 'Edit factor' }}</flux:heading>
                <flux:subheading>{{ $version->name }}</flux:subheading>
            </div>

            <flux:input wire:model="factorDescription" label="Description" placeholder="ex. High Scale" autofocus />
            <flux:input wire:model="factorAbbreviation" label="Abbreviation" placeholder="ex. HS" />

            <div class="grid grid-cols-2 gap-4">
                <flux:input type="number" min="0" max="255" wire:model="factorBest" label="Best" />
                <flux:input type="number" min="0" max="255" wire:model="factorWorst" label="Worst" />
                <flux:input type="number" min="1" max="255" wire:model="factorIntervalBy" label="Interval By" />
                <flux:input type="number" min="1" max="255" wire:model="factorMultiplier" label="Multiplier" />
            </div>

            <flux:input
                type="number"
                min="0"
                max="255"
                wire:model="factorTolerance"
                label="Tolerance"
                description="Future-proofing only — tolerance is currently enforced at the Room level, not per-factor."
            />

            @if ($errors->any())
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>Please correct the errors above before saving.</flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">
                    {{ $editingFactorId === null ? 'Add' : 'Save' }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
