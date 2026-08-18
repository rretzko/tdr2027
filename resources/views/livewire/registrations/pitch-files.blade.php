<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('registrations.index') }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Registrations</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('registrations.version', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Pitch Files</span>
    </div>

    <flux:heading size="xl" class="mb-1">Pitch Files</flux:heading>
    <flux:text size="sm" class="text-zinc-500 mb-6">{{ $version->name }} — audio and reference files by voice part</flux:text>

    <div class="flex flex-col sm:flex-row gap-3 mb-4">
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
                @if (! $visibleToTeacher)
                    Pitch files for this Event are only shown to candidates.
                @elseif ($voicePartFilter !== '' || $nameFilter !== '')
                    No pitch files match your filter.
                @else
                    No pitch files have been added to this Version yet.
                @endif
            </flux:callout.text>
        </flux:callout>
    @else
        {{-- Cards below lg:, table at lg:+ --}}
        <div class="lg:hidden space-y-3">
            @foreach ($pitchFiles as $pitchFile)
                @php
                    // New objects in this bucket default to private — a plain
                    // ->url() 403s silently, so every playback/read link needs
                    // a signed URL (same pattern as the admin Pitch Files page).
                    $pitchFileUrl = \Illuminate\Support\Facades\Storage::disk('s3')->temporaryUrl($pitchFile->url, now()->addMinutes(30));
                @endphp
                <flux:card size="sm">
                    <div class="flex items-start justify-between gap-3 mb-2">
                        <div class="min-w-0">
                            <flux:heading size="base" class="truncate">{{ $pitchFile->name }}</flux:heading>
                            <flux:badge color="zinc" size="sm">{{ $pitchFile->voicePart->name }}</flux:badge>
                        </div>
                        @if (strtolower($pitchFile->name) === 'pdf')
                            <a href="{{ $pitchFileUrl }}" target="_blank" rel="noopener" class="shrink-0 text-sm text-blue-600 hover:underline dark:text-blue-400">
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
                                <audio controls preload="metadata" class="w-full">
                                    <source src="{{ $pitchFileUrl }}">
                                </audio>
                            </div>
                        </div>
                    @endif

                    @if ($pitchFile->description)
                        <flux:text size="sm" class="text-zinc-500">{{ $pitchFile->description }}</flux:text>
                    @endif
                </flux:card>
            @endforeach
        </div>

        <div class="hidden lg:block">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Voice Part</flux:table.column>
                    <flux:table.column>Description</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($pitchFiles as $pitchFile)
                        @php
                            $pitchFileUrl = \Illuminate\Support\Facades\Storage::disk('s3')->temporaryUrl($pitchFile->url, now()->addMinutes(30));
                        @endphp
                        <flux:table.row :key="$pitchFile->id">
                            <flux:table.cell>
                                <div class="font-medium">{{ $pitchFile->name }}</div>
                                @if (strtolower($pitchFile->name) === 'pdf')
                                    <a href="{{ $pitchFileUrl }}" target="_blank" rel="noopener" class="text-sm text-blue-600 hover:underline dark:text-blue-400">
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
                                            <audio controls preload="metadata" class="max-w-[220px]">
                                                <source src="{{ $pitchFileUrl }}">
                                            </audio>
                                        </div>
                                    </div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge color="zinc" size="sm">{{ $pitchFile->voicePart->name }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="max-w-[420px] whitespace-normal break-words text-zinc-500">
                                    {{ $pitchFile->description ?? '—' }}
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</div>
