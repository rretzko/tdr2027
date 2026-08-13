<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.reports', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Reports</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Payment Reconciliation</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">Payment Reconciliation</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — balances and unreconciled payments</flux:text>
        </div>

        <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route('events.versions.reports.payment-reconciliation.pdf', $version)" target="_blank">
            Export PDF
        </flux:button>
    </div>

    @php
        $balanceBadge = function (int $cents) {
            if ($cents > 0) {
                return ['color' => 'red', 'label' => '$'.number_format($cents / 100, 2).' due'];
            }
            if ($cents < 0) {
                return ['color' => 'blue', 'label' => '$'.number_format(abs($cents) / 100, 2).' overpaid'];
            }
            return ['color' => 'green', 'label' => 'Paid in full'];
        };
    @endphp

    {{-- Version-level rollup --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
        <flux:card size="sm">
            <flux:text size="sm" class="text-zinc-500">Total Due</flux:text>
            <flux:heading size="lg">${{ number_format($versionTotals['dueCents'] / 100, 2) }}</flux:heading>
        </flux:card>
        <flux:card size="sm">
            <flux:text size="sm" class="text-zinc-500">Total Paid</flux:text>
            <flux:heading size="lg">${{ number_format($versionTotals['paidCents'] / 100, 2) }}</flux:heading>
        </flux:card>
        <flux:card size="sm">
            <flux:text size="sm" class="text-zinc-500">Version Balance</flux:text>
            @php $versionBadge = $balanceBadge($versionTotals['balanceCents']); @endphp
            <flux:heading size="lg">
                <flux:badge color="{{ $versionBadge['color'] }}">{{ $versionBadge['label'] }}</flux:badge>
            </flux:heading>
        </flux:card>
    </div>

    {{-- Needs Reconciliation --}}
    <div class="mb-8">
        <flux:heading size="lg" class="mb-1">Needs Reconciliation</flux:heading>
        <flux:text size="sm" class="text-zinc-500 mb-3">
            Payments not yet fully allocated to specific candidates — they don't count toward any school's balance
            above until allocated.
        </flux:text>

        @if ($needsReconciliation->isEmpty())
            <flux:callout variant="success" icon="check-circle">
                <flux:callout.text>Nothing needs reconciliation right now.</flux:callout.text>
            </flux:callout>
        @else
            <div class="space-y-3">
                @foreach ($needsReconciliation as $transaction)
                    <flux:card size="sm">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <span class="font-medium">${{ number_format($transaction->amountInDollars(), 2) }}</span>
                                <span class="text-zinc-500"> total</span>
                                @if ($transaction->school)
                                    <span class="text-zinc-500"> — {{ $transaction->school->name }}</span>
                                @endif
                                @if ($transaction->payerTeacher)
                                    <span class="text-zinc-500"> ({{ $transaction->payerTeacher->user->name }})</span>
                                @endif
                                <div class="text-sm text-amber-600 dark:text-amber-400">
                                    ${{ number_format($transaction->unallocatedAmount() / 100, 2) }} still unallocated
                                </div>
                                @if ($transaction->reference_number)
                                    <div class="text-sm text-zinc-500">Ref: {{ $transaction->reference_number }}</div>
                                @endif
                            </div>
                            <flux:button size="sm" variant="primary" wire:click="openAllocate({{ $transaction->id }})">
                                Allocate
                            </flux:button>
                        </div>
                    </flux:card>
                @endforeach
            </div>
        @endif
    </div>

    {{-- School balances --}}
    <div class="mb-8">
        <flux:heading size="lg" class="mb-3">Balances by School</flux:heading>

        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by school..." icon="magnifying-glass" class="sm:max-w-sm mb-4" />

        @if ($schoolRows->isEmpty())
            <flux:text class="text-zinc-500">
                {{ $search !== '' ? 'No schools match your search.' : 'No registered candidates yet.' }}
            </flux:text>
        @else
            {{-- Cards below md:, table at md:+ --}}
            <div class="md:hidden space-y-3">
                @foreach ($schoolRows as $row)
                    @php $badge = $balanceBadge($row['balanceCents']); @endphp
                    <flux:card size="sm">
                        <div class="flex items-start justify-between gap-3 mb-2">
                            <flux:heading size="base">{{ $row['school']->name ?? '—' }}</flux:heading>
                            <flux:badge color="{{ $badge['color'] }}" size="sm">{{ $badge['label'] }}</flux:badge>
                        </div>
                        <div class="text-sm text-zinc-500">
                            {{ $row['count'] }} registered — due ${{ number_format($row['dueCents'] / 100, 2) }}, paid ${{ number_format($row['paidCents'] / 100, 2) }}
                        </div>
                    </flux:card>
                @endforeach
            </div>

            <flux:table class="hidden md:table">
                <flux:table.columns>
                    <flux:table.column>School</flux:table.column>
                    <flux:table.column>Registered</flux:table.column>
                    <flux:table.column>Due</flux:table.column>
                    <flux:table.column>Paid</flux:table.column>
                    <flux:table.column>Balance</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($schoolRows as $row)
                        @php $badge = $balanceBadge($row['balanceCents']); @endphp
                        <flux:table.row>
                            <flux:table.cell class="font-medium">{{ $row['school']->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $row['count'] }}</flux:table.cell>
                            <flux:table.cell>${{ number_format($row['dueCents'] / 100, 2) }}</flux:table.cell>
                            <flux:table.cell>${{ number_format($row['paidCents'] / 100, 2) }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge color="{{ $badge['color'] }}" size="sm">{{ $badge['label'] }}</flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    {{-- Allocate Payment modal --}}
    <flux:modal name="allocate-payment" class="w-full max-w-lg">
        <div class="space-y-6">
            <flux:heading>Allocate Payment</flux:heading>

            @if ($allocatingTransaction)
                <flux:text size="sm" class="text-zinc-500">
                    ${{ number_format($allocatingTransaction->unallocatedAmount() / 100, 2) }} remaining to allocate
                    out of ${{ number_format($allocatingTransaction->amountInDollars(), 2) }} total.
                </flux:text>

                <flux:input
                    wire:model.live.debounce.300ms="allocationCandidateSearch"
                    placeholder="Search candidates by name..."
                    icon="magnifying-glass"
                />

                <flux:error name="allocationAmounts" />

                <div class="space-y-3 max-h-80 overflow-y-auto">
                    @forelse ($allocationCandidates as $candidate)
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <flux:text size="sm">{{ $candidate->student->user->last_name }}, {{ $candidate->student->user->first_name }}</flux:text>
                                <div class="text-xs text-zinc-500">{{ $candidate->school->name ?? '—' }}</div>
                            </div>
                            <flux:input
                                wire:model="allocationAmounts.{{ $candidate->id }}"
                                type="number" step="0.01" min="0" placeholder="0.00"
                                class="max-w-32"
                            />
                        </div>
                    @empty
                        <flux:text size="sm" class="text-zinc-500">
                            {{ $allocationCandidateSearch !== '' ? 'No candidates match your search.' : 'This payment has no associated school to suggest candidates from — search by name above.' }}
                        </flux:text>
                    @endforelse
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="saveAllocations">Save</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
