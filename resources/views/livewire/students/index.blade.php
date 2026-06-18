<div>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <flux:heading size="xl">Students</flux:heading>

        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by name..." icon="magnifying-glass" class="sm:max-w-xs" />
    </div>

    {{-- Cards below md:, full table at md: and up --}}
    <div class="md:hidden space-y-3">
        @forelse ($rows as $row)
            <flux:card size="sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <flux:heading size="base">{{ $row->student->user->name }}</flux:heading>
                        <flux:text size="sm" class="text-zinc-500">{{ $row->school->name }}</flux:text>
                    </div>

                    @if ($row->is_active)
                        <flux:badge color="green" size="sm">Active</flux:badge>
                    @else
                        <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                    @endif
                </div>

                <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                    <div>
                        <dt class="text-zinc-400">Subject</dt>
                        <dd>{{ $row->subject->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-400">Grade</dt>
                        <dd>{{ $gradeByRowId[$row->id] ?? '—' }}</dd>
                    </div>
                </dl>

                <div class="mt-4 grid grid-cols-3 gap-2">
                    <flux:modal.trigger name="edit-student">
                        <flux:button size="sm" variant="outline" class="w-full" wire:click="edit({{ $row->id }})">
                            Edit
                        </flux:button>
                    </flux:modal.trigger>
                    <flux:button size="sm" variant="outline" :disabled="! $row->is_active" wire:click="deactivate({{ $row->id }})">
                        Deactivate
                    </flux:button>
                    <flux:button size="sm" variant="danger" wire:click="remove({{ $row->id }})" wire:confirm="Remove {{ $row->student->user->name }} from your roster? This cannot be undone.">
                        Remove
                    </flux:button>
                </div>
            </flux:card>
        @empty
            <flux:card size="sm" class="text-center text-zinc-500">
                No students found.
            </flux:card>
        @endforelse

        <flux:pagination :paginator="$rows" />
    </div>

    <div class="hidden md:block">
        <flux:table :paginate="$rows">
            <flux:table.columns>
                <flux:table.column sortable :sorted="$sortColumn === 'name'" :direction="$sortDirection" wire:click="sortBy('name')">
                    Name
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortColumn === 'school'" :direction="$sortDirection" wire:click="sortBy('school')">
                    School
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortColumn === 'subject'" :direction="$sortDirection" wire:click="sortBy('subject')">
                    Subject
                </flux:table.column>
                <flux:table.column>Grade</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Actions</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($rows as $row)
                    <flux:table.row :key="$row->id">
                        <flux:table.cell>{{ $row->student->user->name }}</flux:table.cell>
                        <flux:table.cell>{{ $row->school->name }}</flux:table.cell>
                        <flux:table.cell>{{ $row->subject->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $gradeByRowId[$row->id] ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($row->is_active)
                                <flux:badge color="green" size="sm">Active</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center justify-end gap-1">
                                <flux:modal.trigger name="edit-student">
                                    <flux:button size="sm" variant="ghost" icon="pencil" inset="right" aria-label="Edit student" wire:click="edit({{ $row->id }})" />
                                </flux:modal.trigger>

                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" inset="right" aria-label="Student actions" />

                                    <flux:menu>
                                        <flux:menu.item :disabled="! $row->is_active" wire:click="deactivate({{ $row->id }})">
                                            Deactivate
                                        </flux:menu.item>
                                        <flux:menu.item variant="danger" wire:click="remove({{ $row->id }})" wire:confirm="Remove {{ $row->student->user->name }} from your roster? This cannot be undone.">
                                            Remove
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" class="text-center text-zinc-500">
                            No students found.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="edit-student" class="md:w-96">
        <form wire:submit="saveEdit" class="space-y-6">
            <div>
                <flux:heading size="lg">Edit student</flux:heading>
                <flux:subheading>Update the subject and your role for this student.</flux:subheading>
            </div>

            <flux:select wire:model="edit_subject" label="Subject">
                @foreach ($subjectOptions as $subject)
                    <flux:select.option value="{{ $subject->value }}">{{ $subject->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="edit_role" label="Your role">
                <flux:select.option value="primary">Primary teacher / director</flux:select.option>
                <flux:select.option value="coteacher">Co-teacher / assistant director</flux:select.option>
            </flux:select>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
