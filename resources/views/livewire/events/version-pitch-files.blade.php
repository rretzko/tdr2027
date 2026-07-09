<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.edit', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Pitch Files</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">Pitch Files</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — audio and reference files by voice part</flux:text>
        </div>

        <flux:modal.trigger name="pitch-file-form">
            <flux:button size="sm" variant="primary" icon="plus" wire:click="add">
                Add pitch file
            </flux:button>
        </flux:modal.trigger>
    </div>

    <div class="flex flex-col sm:flex-row gap-3 mb-4">
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="Search by name or description..."
            icon="magnifying-glass"
            class="sm:max-w-xs"
        />
        <flux:select wire:model.live="voicePartFilter" placeholder="All voice parts" class="sm:max-w-2xs">
            <flux:select.option value="">All voice parts</flux:select.option>
            @foreach ($availableVoiceParts as $voicePart)
                <flux:select.option value="{{ $voicePart->id }}">{{ $voicePart->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="nameFilter" placeholder="All file types" class="sm:max-w-2xs">
            <flux:select.option value="">All file types</flux:select.option>
            @foreach ($nameOptions as $option)
                <flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($pitchFiles->isEmpty())
        <flux:callout variant="info" icon="magnifying-glass">
            <flux:callout.text>
                @if ($search !== '' || $voicePartFilter !== '' || $nameFilter !== '')
                    No pitch files match your search or filter.
                @else
                    No pitch files have been added to this Version yet.
                @endif
            </flux:callout.text>
        </flux:callout>
    @else
        {{-- Cards below lg:, table at lg:+ --}}
        <div class="lg:hidden space-y-3">
            @foreach ($pitchFiles as $pitchFile)
                <flux:card size="sm">
                    <div class="flex items-start justify-between gap-3 mb-2">
                        <div class="min-w-0">
                            <flux:heading size="base" class="truncate">{{ $pitchFile->name }}</flux:heading>
                            <flux:badge color="zinc" size="sm">{{ $pitchFile->voicePart->name }}</flux:badge>
                        </div>
                        @if (strtolower($pitchFile->name) === 'pdf')
                            <a href="{{ \Illuminate\Support\Facades\Storage::disk('s3')->url($pitchFile->url) }}" target="_blank" rel="noopener" class="shrink-0 text-sm text-blue-600 hover:underline dark:text-blue-400">
                                Read
                            </a>
                        @else
                            <button
                                type="button"
                                @click="
                                    let panel = $el.nextElementSibling;
                                    let isOpen = panel.style.gridTemplateRows === '1fr';
                                    panel.style.gridTemplateRows = isOpen ? '0fr' : '1fr';
                                    $el.textContent = isOpen ? 'Listen' : 'Hide';
                                "
                                class="shrink-0 text-sm text-blue-600 hover:underline dark:text-blue-400"
                            >Listen</button>
                        @endif
                    </div>

                    @if (strtolower($pitchFile->name) !== 'pdf')
                        <div class="grid transition-[grid-template-rows] duration-200 ease-out mb-2" style="grid-template-rows: 0fr;">
                            <div class="overflow-hidden min-h-0">
                                <audio controls preload="none" class="w-full">
                                    <source src="{{ \Illuminate\Support\Facades\Storage::disk('s3')->url($pitchFile->url) }}">
                                </audio>
                            </div>
                        </div>
                    @endif

                    @if ($pitchFile->description)
                        <flux:text size="sm" class="text-zinc-500 mb-2">{{ $pitchFile->description }}</flux:text>
                    @endif

                    <div class="flex items-center gap-2 mt-2">
                        <flux:input type="number" min="1" max="999" wire:model="orderInputs.{{ $pitchFile->id }}" size="sm" class="w-20" />

                        <flux:spacer />

                        <flux:modal.trigger name="pitch-file-form">
                            <flux:button size="sm" variant="outline" wire:click="edit({{ $pitchFile->id }})">
                                Edit
                            </flux:button>
                        </flux:modal.trigger>

                        <flux:button size="sm" variant="danger" wire:click="remove({{ $pitchFile->id }})" wire:confirm="Remove &quot;{{ $pitchFile->name }}&quot;? This cannot be undone.">
                            Remove
                        </flux:button>
                    </div>
                </flux:card>
            @endforeach

            <flux:button size="sm" wire:click="saveOrder">Save Order</flux:button>
        </div>

        <div class="hidden lg:block">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column></flux:table.column>
                    <flux:table.column sortable :sorted="$sortColumn === 'name'" :direction="$sortDirection" wire:click="sortBy('name')">
                        Name
                    </flux:table.column>
                    <flux:table.column sortable :sorted="$sortColumn === 'voice_part'" :direction="$sortDirection" wire:click="sortBy('voice_part')">
                        Voice Part
                    </flux:table.column>
                    <flux:table.column sortable :sorted="$sortColumn === 'description'" :direction="$sortDirection" wire:click="sortBy('description')">
                        Description
                    </flux:table.column>
                    <flux:table.column sortable :sorted="$sortColumn === 'order_by'" :direction="$sortDirection" wire:click="sortBy('order_by')">
                        Order
                    </flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($pitchFiles as $pitchFile)
                        <flux:table.row
                            :key="$pitchFile->id"
                            draggable="true"
                            @dragstart="$event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('text/plain', '{{ $pitchFile->id }}'); setTimeout(() => $el.classList.add('opacity-40'))"
                            @dragend="$el.classList.remove('opacity-40')"
                            @dragover.prevent="$event.dataTransfer.dropEffect = 'move'; $el.classList.add('bg-zinc-50', 'dark:bg-zinc-800')"
                            @dragleave="$el.classList.remove('bg-zinc-50', 'dark:bg-zinc-800')"
                            @drop.prevent="
                                $el.classList.remove('bg-zinc-50', 'dark:bg-zinc-800');
                                $wire.reorderPitchFiles(parseInt($event.dataTransfer.getData('text/plain')), {{ $loop->index }});
                            "
                            class="transition-[background-color,opacity] cursor-grab active:cursor-grabbing"
                        >
                            <flux:table.cell>
                                <flux:icon.bars-3 variant="micro" class="cursor-grab text-zinc-400" />
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="font-medium">{{ $pitchFile->name }}</div>
                                @if (strtolower($pitchFile->name) === 'pdf')
                                    <a href="{{ \Illuminate\Support\Facades\Storage::disk('s3')->url($pitchFile->url) }}" target="_blank" rel="noopener" class="text-sm text-blue-600 hover:underline dark:text-blue-400">
                                        Read
                                    </a>
                                @else
                                    <button
                                        type="button"
                                        @click="
                                            let panel = $el.nextElementSibling;
                                            let isOpen = panel.style.gridTemplateRows === '1fr';
                                            panel.style.gridTemplateRows = isOpen ? '0fr' : '1fr';
                                            $el.textContent = isOpen ? 'Listen' : 'Hide';
                                        "
                                        class="text-sm text-blue-600 hover:underline dark:text-blue-400"
                                    >Listen</button>
                                    <div class="grid transition-[grid-template-rows] duration-200 ease-out" style="grid-template-rows: 0fr;">
                                        <div class="overflow-hidden min-h-0 mt-1">
                                            <audio controls preload="none" class="max-w-[220px]">
                                                <source src="{{ \Illuminate\Support\Facades\Storage::disk('s3')->url($pitchFile->url) }}">
                                            </audio>
                                        </div>
                                    </div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge color="zinc" size="sm">{{ $pitchFile->voicePart->name }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="max-w-[280px] whitespace-normal break-words text-zinc-500">
                                    {{ $pitchFile->description ?? '—' }}
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>{{ $pitchFile->order_by }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center gap-2">
                                    <flux:modal.trigger name="pitch-file-form">
                                        <flux:button size="sm" variant="outline" wire:click="edit({{ $pitchFile->id }})">
                                            Edit
                                        </flux:button>
                                    </flux:modal.trigger>

                                    <flux:button size="sm" variant="danger" wire:click="remove({{ $pitchFile->id }})" wire:confirm="Remove &quot;{{ $pitchFile->name }}&quot;? This cannot be undone.">
                                        Remove
                                    </flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            <flux:text size="sm" class="text-zinc-400 mt-2">Drag a row and drop it on another to reorder, or use Save Order in the mobile view.</flux:text>
        </div>
    @endif

    <flux:modal name="pitch-file-form" class="md:w-[32rem]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId === null ? 'Add pitch file' : 'Edit pitch file' }}</flux:heading>
                <flux:subheading>{{ $version->name }}</flux:subheading>
            </div>

            <flux:input wire:model="name" label="Name (ex. scales, solo, pdf, etc.)" placeholder="ex. scales, solo, pdf, etc." autofocus />

            <flux:select wire:model="voice_part_id" label="Voice Part" placeholder="Select a voice part...">
                @foreach ($availableVoiceParts as $voicePart)
                    <flux:select.option value="{{ $voicePart->id }}">{{ $voicePart->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:textarea wire:model="description" label="Description" rows="2" />

            <flux:field>
                <flux:label>{{ $editingId === null ? 'Audio / PDF File' : 'Replace File (optional)' }}</flux:label>
                <input
                    type="file"
                    wire:model="newFile"
                    accept="audio/*,video/*,.pdf"
                    class="block w-full text-sm text-zinc-600 file:mr-4 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:file:text-zinc-300"
                />
                <div wire:loading wire:target="newFile">
                    <flux:text size="sm" class="mt-1 text-zinc-400">Uploading…</flux:text>
                </div>
                <flux:error name="newFile" />
            </flux:field>

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
                    {{ $editingId === null ? 'Add' : 'Save' }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
