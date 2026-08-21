<div>
    <div class="mb-6">
        <flux:heading size="xl">Issues</flux:heading>
        <flux:subheading>Bug reports, enhancement requests, kudos, and comments submitted by users.</flux:subheading>
    </div>

    <div class="mb-6 flex flex-wrap items-end gap-4">
        <flux:field class="w-48">
            <flux:label>Type</flux:label>
            <flux:select wire:model.live="typeFilter" placeholder="All types">
                <flux:select.option value="bug">Bug</flux:select.option>
                <flux:select.option value="enhancement">Enhancement</flux:select.option>
                <flux:select.option value="kudo">Kudo</flux:select.option>
                <flux:select.option value="comment">Comment</flux:select.option>
            </flux:select>
        </flux:field>

        <flux:field class="w-48">
            <flux:label>Status</flux:label>
            <flux:select wire:model.live="statusFilter" placeholder="All statuses">
                @foreach ($statuses as $status)
                    <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </flux:field>
    </div>

    {{-- Mobile: card list --}}
    <div class="flex flex-col gap-3 md:hidden">
        @forelse ($issues as $issue)
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-2 flex items-start justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <flux:badge>{{ $issue->request_type->label() }}</flux:badge>
                        @if ($issue->is_private)
                            <flux:badge color="zinc" icon="lock-closed">Private</flux:badge>
                        @endif
                    </div>
                    <flux:text size="sm" class="text-zinc-500">{{ $issue->created_at->format('M j, Y g:ia') }}</flux:text>
                </div>
                <flux:text class="mb-1 font-medium">{{ $issue->user->first_name }} {{ $issue->user->last_name }}</flux:text>
                <flux:text class="mb-2 truncate text-zinc-500" size="sm">{{ $issue->from_page }}</flux:text>
                <flux:text class="mb-3">{{ $issue->request }}</flux:text>
                @if ($issue->file_path !== null)
                    <flux:link :href="$issue->fileUrl()" target="_blank" class="mb-3 block">View attachment</flux:link>
                @endif
                <flux:select wire:change="updateStatus({{ $issue->id }}, $event.target.value)">
                    @foreach ($statuses as $status)
                        <flux:select.option value="{{ $status->value }}" :selected="$issue->status === $status">{{ $status->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
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
                <flux:table.column>Reported By</flux:table.column>
                <flux:table.column>Type</flux:table.column>
                <flux:table.column>Request</flux:table.column>
                <flux:table.column>From Page</flux:table.column>
                <flux:table.column>File</flux:table.column>
                <flux:table.column>Status</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($issues as $issue)
                    <flux:table.row :key="$issue->id">
                        <flux:table.cell class="whitespace-nowrap text-zinc-500">{{ $issue->created_at->format('M j, Y g:ia') }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $issue->user->first_name }} {{ $issue->user->last_name }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <flux:badge>{{ $issue->request_type->label() }}</flux:badge>
                                @if ($issue->is_private)
                                    <flux:badge color="zinc" icon="lock-closed">Private</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="max-w-md truncate">{{ $issue->request }}</flux:table.cell>
                        <flux:table.cell class="max-w-xs truncate text-zinc-500" title="{{ $issue->from_page }}">{{ $issue->from_page }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($issue->file_path !== null)
                                <flux:link :href="$issue->fileUrl()" target="_blank">View</flux:link>
                            @else
                                <flux:text class="text-zinc-400">&mdash;</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:select size="sm" wire:change="updateStatus({{ $issue->id }}, $event.target.value)">
                                @foreach ($statuses as $status)
                                    <flux:select.option value="{{ $status->value }}" :selected="$issue->status === $status">{{ $status->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7" class="text-zinc-500">No feedback submitted yet.</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</div>
