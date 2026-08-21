<div>
    <div class="mb-6">
        <flux:heading size="xl">Feedback</flux:heading>
    </div>

    <flux:button.group class="mb-6">
        <flux:button :variant="$activeTab === 'report' ? 'filled' : 'ghost'" wire:click="$set('activeTab', 'report')">Report</flux:button>
        <flux:button :variant="$activeTab === 'history' ? 'filled' : 'ghost'" wire:click="$set('activeTab', 'history')">History</flux:button>
    </flux:button.group>

    @if ($activeTab === 'report')
        <form wire:submit="submit" class="max-w-xl space-y-6">
            <flux:field>
                <flux:label>Reported By</flux:label>
                <flux:input :value="auth()->user()->first_name.' '.auth()->user()->last_name" readonly disabled />
            </flux:field>

            <flux:field>
                <flux:label>From Page</flux:label>
                <flux:input wire:model="from_page" readonly disabled />
            </flux:field>

            <flux:radio.group wire:model="request_type" label="Request Type" variant="segmented">
                <flux:radio value="bug" label="Bug" />
                <flux:radio value="enhancement" label="Enhancement" />
                <flux:radio value="kudo" label="Kudo" />
                <flux:radio value="comment" label="Comment" />
            </flux:radio.group>
            <flux:error name="request_type" />

            <flux:field>
                <flux:label>Request</flux:label>
                <flux:textarea wire:model="request" placeholder="Describe your feedback..." rows="5" />
                <flux:error name="request" />
            </flux:field>

            <flux:field>
                <flux:label>Upload File or Image</flux:label>
                <input type="file" wire:model="newFile" class="block text-sm text-zinc-600 dark:text-zinc-300">
                <flux:error name="newFile" />
                <div wire:loading wire:target="newFile" class="text-sm text-zinc-500">Uploading...</div>
            </flux:field>

            <flux:checkbox wire:model="is_private" label="Private (will not be included in the History page)" />

            @if ($errors->any())
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>Please correct the errors above before submitting.</flux:callout.text>
                </flux:callout>
            @endif

            <flux:button type="submit" variant="primary">Submit Feedback</flux:button>
        </form>
    @else
        {{-- Mobile: card list --}}
        <div class="flex flex-col gap-3 md:hidden">
            @forelse ($history as $item)
                <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="mb-2 flex items-start justify-between gap-2">
                        <flux:badge>{{ $item->request_type->label() }}</flux:badge>
                        <flux:badge :color="match ($item->status->value) { 'resolved' => 'green', 'in_progress' => 'yellow', default => 'zinc' }">{{ $item->status->label() }}</flux:badge>
                    </div>
                    <flux:text class="mb-1">{{ $item->request }}</flux:text>
                    <flux:text size="sm" class="text-zinc-500">{{ $item->created_at->format('M j, Y g:ia') }}</flux:text>
                </div>
            @empty
                <flux:text class="text-zinc-500">No feedback submitted yet.</flux:text>
            @endforelse
        </div>

        {{-- Desktop: full table --}}
        <div class="hidden md:block">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Date</flux:table.column>
                    <flux:table.column>Type</flux:table.column>
                    <flux:table.column>Request</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($history as $item)
                        <flux:table.row :key="$item->id">
                            <flux:table.cell class="whitespace-nowrap text-zinc-500">{{ $item->created_at->format('M j, Y g:ia') }}</flux:table.cell>
                            <flux:table.cell><flux:badge>{{ $item->request_type->label() }}</flux:badge></flux:table.cell>
                            <flux:table.cell class="max-w-md truncate">{{ $item->request }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="match ($item->status->value) { 'resolved' => 'green', 'in_progress' => 'yellow', default => 'zinc' }">{{ $item->status->label() }}</flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="text-zinc-500">No feedback submitted yet.</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</div>
