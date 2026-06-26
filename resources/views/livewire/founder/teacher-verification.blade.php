<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">Teacher Verification</flux:heading>
            <flux:subheading>Manually verify teachers' school emails or run the annual re-verification reset.</flux:subheading>
        </div>
        <flux:button icon="arrow-path" variant="danger" wire:click="confirmAnnualReset">
            Annual Reset &amp; Send Emails
        </flux:button>
    </div>

    <div class="mb-4">
        <flux:checkbox wire:model.live="pendingOnly" label="Show pending only" />
    </div>

    {{-- Mobile: card list --}}
    <div class="flex flex-col gap-3 md:hidden">
        @forelse ($pivots as $pivot)
            @php
                $isPending  = filled($pivot->school_email) && blank($pivot->verified_at);
                $isVerified = filled($pivot->verified_at);
            @endphp
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-2 flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <flux:text class="font-medium">
                            {{ $pivot->teacher->user->last_name }}, {{ $pivot->teacher->user->first_name }}
                        </flux:text>
                        <flux:text size="sm" class="truncate text-zinc-500">{{ $pivot->teacher->user->email }}</flux:text>
                        <flux:text size="sm" class="font-medium text-zinc-700 dark:text-zinc-300">{{ $pivot->school->name }}</flux:text>
                        @if ($pivot->school_email)
                            <flux:text size="sm" class="truncate text-zinc-500">{{ $pivot->school_email }}</flux:text>
                        @endif
                    </div>
                    <div class="shrink-0">
                        @if ($isVerified)
                            <flux:badge color="green" size="sm">Verified</flux:badge>
                        @elseif ($isPending)
                            <flux:badge color="yellow" size="sm">Pending</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm">No Email</flux:badge>
                        @endif
                    </div>
                </div>
                @if ($isPending)
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:button size="sm" icon="check" variant="primary" wire:click="verifyTeacher({{ $pivot->id }})">
                            Verify
                        </flux:button>
                        <flux:button size="sm" icon="envelope" wire:click="sendVerificationEmail({{ $pivot->id }})">
                            Send Email
                        </flux:button>
                    </div>
                @endif
            </div>
        @empty
            <flux:text class="text-zinc-500">
                {{ $pendingOnly ? 'No pending verifications.' : 'No teacher school records found.' }}
            </flux:text>
        @endforelse
    </div>

    {{-- Desktop: full table --}}
    <div class="hidden md:block">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Teacher</flux:table.column>
                <flux:table.column>School</flux:table.column>
                <flux:table.column>School Email</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($pivots as $pivot)
                    @php
                        $isPending  = filled($pivot->school_email) && blank($pivot->verified_at);
                        $isVerified = filled($pivot->verified_at);
                    @endphp
                    <flux:table.row :key="$pivot->id">
                        <flux:table.cell>
                            <flux:text class="font-medium">
                                {{ $pivot->teacher->user->last_name }}, {{ $pivot->teacher->user->first_name }}
                            </flux:text>
                            <flux:text size="sm" class="text-zinc-500">{{ $pivot->teacher->user->email }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>{{ $pivot->school->name }}</flux:table.cell>
                        <flux:table.cell class="text-zinc-500">{{ $pivot->school_email ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($isVerified)
                                <flux:badge color="green">Verified</flux:badge>
                            @elseif ($isPending)
                                <flux:badge color="yellow">Pending</flux:badge>
                            @else
                                <flux:badge color="zinc">No Email</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($isPending)
                                <div class="flex items-center gap-2">
                                    <flux:button size="sm" icon="check" variant="primary" wire:click="verifyTeacher({{ $pivot->id }})">
                                        Verify
                                    </flux:button>
                                    <flux:button size="sm" icon="envelope" wire:click="sendVerificationEmail({{ $pivot->id }})">
                                        Send Email
                                    </flux:button>
                                </div>
                            @elseif ($isVerified)
                                <flux:text size="sm" class="text-zinc-400">{{ $pivot->verified_at->format('M j, Y') }}</flux:text>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-zinc-500">
                            {{ $pendingOnly ? 'No pending verifications.' : 'No teacher school records found.' }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    {{-- Annual reset confirmation modal --}}
    <flux:modal name="confirm-annual-reset" class="w-full max-w-md">
        <div class="space-y-4">
            <flux:heading>Annual Verification Reset</flux:heading>
            <flux:text>
                This will set <strong>all</strong> school email verifications to null and immediately queue
                a re-verification email to every teacher who has a school email on file.
                This action cannot be undone.
            </flux:text>
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.text>Typically run on September 1 each year.</flux:callout.text>
            </flux:callout>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="resetAllAndSendEmails" wire:loading.attr="disabled">
                    Reset All &amp; Send Emails
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
