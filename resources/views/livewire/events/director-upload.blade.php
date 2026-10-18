<div class="space-y-6">
    <div>
        <flux:heading size="lg">Upload Directors</flux:heading>
        <flux:subheading>{{ $version->name }} — create director accounts as needed and invite them to this Version</flux:subheading>
    </div>

    @if ($step === 'upload')
        <form wire:submit="previewUpload" class="space-y-6">
            <flux:callout variant="info" icon="information-circle">
                <flux:callout.text>
                    <a href="{{ route('events.versions.invitations.director-template', $version) }}" class="font-medium underline">Download the CSV template</a>,
                    fill in one row per director, and upload it here. You'll see a preview before anything is saved.
                    Directors already in the system are matched by email, and their existing details aren't changed.
                </flux:callout.text>
            </flux:callout>

            <div class="text-sm space-y-1">
                <div>
                    <span class="font-medium">Required:</span>
                    <span class="text-zinc-600 dark:text-zinc-400">{{ implode(', ', $requiredHeaders) }}</span>
                </div>
                <div>
                    <span class="font-medium">Optional:</span>
                    <span class="text-zinc-600 dark:text-zinc-400">{{ implode(', ', $optionalHeaders) }}</span>
                </div>
                <flux:text size="sm" class="text-zinc-500">
                    school_type: school or studio · role: primary or coteacher · subjects: band|chorus|orchestra · school_state defaults to NJ · rows using @example.com emails are skipped.
                </flux:text>
            </div>

            <flux:field>
                <flux:label>CSV file</flux:label>
                <input
                    type="file"
                    wire:model="csvFile"
                    accept=".csv,text/csv"
                    class="block w-full text-sm text-zinc-600 file:mr-4 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:file:text-zinc-300"
                />
                <div wire:loading wire:target="csvFile">
                    <flux:text size="sm" class="mt-1 text-zinc-400">Uploading…</flux:text>
                </div>
                <flux:error name="csvFile" />
            </flux:field>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="csvFile,previewUpload">
                    Preview
                </flux:button>
            </div>
        </form>
    @elseif ($step === 'preview')
        @foreach ($notices as $notice)
            <flux:callout variant="secondary" icon="information-circle">
                <flux:callout.text>{{ $notice }}</flux:callout.text>
            </flux:callout>
        @endforeach

        {{-- Outcome summary --}}
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
            @foreach (\App\Enums\DirectorRowOutcome::cases() as $outcome)
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 px-3 py-2">
                    <flux:badge size="sm" :color="$outcome->color()">{{ $outcome->label() }}</flux:badge>
                    <flux:heading size="lg" class="mt-1">{{ $outcomeCounts[$outcome->value] }}</flux:heading>
                </div>
            @endforeach
        </div>

        {{-- Rows needing a decision --}}
        @php($flagged = collect($preview)->where('outcome', 'possible_duplicate'))
        @if ($flagged->isNotEmpty())
            <div class="space-y-3">
                <flux:heading size="base">Needs review</flux:heading>
                <flux:text size="sm" class="text-zinc-500">These rows look like they may already exist. Choose an existing record, or create a new one.</flux:text>

                @foreach ($flagged as $row)
                    <flux:card size="sm" wire:key="director-review-{{ $row['line'] }}" class="space-y-4">
                        <div>
                            <flux:heading size="base">Line {{ $row['line'] }}: {{ $row['name'] }}</flux:heading>
                            <flux:text size="sm" class="text-zinc-500">{{ $row['email'] }} · {{ $row['school'] }}</flux:text>
                        </div>

                        @if ($row['schoolChoices'] !== [])
                            <flux:radio.group wire:model.live="resolutions.{{ $row['line'] }}.school" label="School">
                                @foreach ($row['schoolChoices'] as $choice)
                                    <flux:radio value="{{ $choice['id'] }}" label="Use {{ $choice['label'] }}" />
                                @endforeach
                                @if ($row['schoolCreateAllowed'])
                                    <flux:radio value="new" label="Create new school &quot;{{ $row['school'] }}&quot;" />
                                @endif
                            </flux:radio.group>
                        @endif

                        @if ($row['personChoices'] !== [])
                            <flux:radio.group wire:model.live="resolutions.{{ $row['line'] }}.person" label="Director">
                                @foreach ($row['personChoices'] as $choice)
                                    <flux:radio value="{{ $choice['id'] }}" label="Use existing: {{ $choice['label'] }}" />
                                @endforeach
                                <flux:radio value="new" label="Create new director {{ $row['name'] }} ({{ $row['email'] }})" />
                            </flux:radio.group>
                        @endif
                    </flux:card>
                @endforeach
            </div>
        @endif

        {{-- Full preview: cards below md:, table at md:+ --}}
        <div class="md:hidden space-y-3">
            @foreach ($preview as $row)
                <flux:card size="sm" wire:key="director-preview-card-{{ $row['line'] }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <flux:heading size="base" class="truncate">{{ $row['name'] ?: '—' }}</flux:heading>
                            <flux:text size="sm" class="text-zinc-500 truncate">Line {{ $row['line'] }} · {{ $row['email'] ?? '—' }}</flux:text>
                            <flux:text size="sm" class="text-zinc-500 truncate">{{ $row['school'] ?? '—' }}</flux:text>
                        </div>
                        @php($outcome = \App\Enums\DirectorRowOutcome::from($row['outcome']))
                        <flux:badge size="sm" :color="$outcome->color()" class="shrink-0">{{ $outcome->label() }}</flux:badge>
                    </div>
                    @include('livewire.events.partials.director-upload-messages', ['row' => $row])
                </flux:card>
            @endforeach
        </div>

        <div class="hidden md:block">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Line</flux:table.column>
                    <flux:table.column>Director</flux:table.column>
                    <flux:table.column>School</flux:table.column>
                    <flux:table.column>Outcome</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($preview as $row)
                        @php($outcome = \App\Enums\DirectorRowOutcome::from($row['outcome']))
                        <flux:table.row wire:key="director-preview-row-{{ $row['line'] }}">
                            <flux:table.cell>{{ $row['line'] }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="font-medium">{{ $row['name'] ?: '—' }}</div>
                                <div class="text-zinc-500">{{ $row['email'] ?? '—' }}</div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="max-w-[220px] whitespace-normal break-words">{{ $row['school'] ?? '—' }}</div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$outcome->color()">{{ $outcome->label() }}</flux:badge>
                                <div class="max-w-[320px] whitespace-normal">
                                    @include('livewire.events.partials.director-upload-messages', ['row' => $row])
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        @if ($errors->any())
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:callout.text>{{ $errors->first() }}</flux:callout.text>
            </flux:callout>
        @endif

        <div class="flex flex-col-reverse sm:flex-row gap-2">
            <flux:button variant="ghost" wire:click="startOver">Upload a different file</flux:button>
            <flux:spacer />
            <flux:button
                variant="primary"
                wire:click="confirmUpload"
                wire:loading.attr="disabled"
                :disabled="$unresolvedCount > 0"
            >
                @if ($unresolvedCount > 0)
                    Resolve {{ $unresolvedCount }} item(s) to continue
                @else
                    Confirm &amp; invite
                @endif
            </flux:button>
        </div>
    @else
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
            @foreach ([
                'invited' => 'Invited',
                'alreadyInvited' => 'Already invited',
                'skipped' => 'Skipped',
                'newUsers' => 'New accounts',
                'newSchools' => 'New schools',
                'newSchoolLinks' => 'New school links',
            ] as $key => $label)
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 px-3 py-2">
                    <flux:text size="sm" class="text-zinc-500">{{ $label }}</flux:text>
                    <flux:heading size="lg">{{ $result[$key] ?? 0 }}</flux:heading>
                </div>
            @endforeach
        </div>

        @php($notInvited = collect($result['rows'] ?? [])->where('status', 'skipped'))
        @if ($notInvited->isNotEmpty())
            <div class="space-y-2">
                <flux:heading size="base">Skipped rows</flux:heading>
                @foreach ($notInvited as $row)
                    <div wire:key="director-result-{{ $row['line'] }}" class="text-sm">
                        <span class="font-medium">Line {{ $row['line'] }} — {{ $row['name'] ?: '—' }}:</span>
                        <span class="text-red-600 dark:text-red-400">{{ $row['message'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="flex gap-2">
            <flux:button variant="ghost" wire:click="startOver">Upload another file</flux:button>
            <flux:spacer />
            <flux:modal.close>
                <flux:button variant="primary">Done</flux:button>
            </flux:modal.close>
        </div>
    @endif
</div>
