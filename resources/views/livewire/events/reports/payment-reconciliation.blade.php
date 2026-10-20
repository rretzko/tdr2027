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

        <div class="flex gap-2">
            <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route('events.versions.reports.payment-reconciliation.export', ['version' => $version, 'format' => 'pdf', 'search' => $search])" target="_blank">
                Export PDF
            </flux:button>
            <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route('events.versions.reports.payment-reconciliation.export', ['version' => $version, 'format' => 'csv', 'search' => $search])">
                Export CSV
            </flux:button>
        </div>
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
                        @foreach ($row['teachers'] as $teacher)
                            @php $cell = \App\Support\Reports\TeacherDisplay::cellPhoneFormatted($teacher); @endphp
                            <div class="mb-2">
                                <flux:text size="sm" class="text-zinc-500">{{ $teacher->user->name }}</flux:text>
                                @if ($teacher->user->email)
                                    <div class="pl-3 text-xs text-zinc-500 dark:text-zinc-400">{{ $teacher->user->email }}</div>
                                @endif
                                @if ($cell)
                                    <div class="pl-3 text-xs text-zinc-500 dark:text-zinc-400">{{ $cell }}</div>
                                @endif
                            </div>
                        @endforeach
                        <div class="text-sm text-zinc-500">
                            {{ $row['count'] }} registered — due ${{ number_format($row['dueCents'] / 100, 2) }}, paid ${{ number_format($row['paidCents'] / 100, 2) }}
                        </div>
                        @if ($canViewPaymentDetails && $row['paidCents'] !== 0 && $row['school'])
                            <div class="mt-3">
                                <flux:button size="sm" variant="outline" icon="eye" wire:click="showPaymentDetails({{ $row['school']->id }})">Details</flux:button>
                            </div>
                        @endif
                    </flux:card>
                @endforeach
            </div>

            <flux:table class="hidden md:table">
                <flux:table.columns>
                    <flux:table.column>School</flux:table.column>
                    <flux:table.column>Teacher</flux:table.column>
                    <flux:table.column>Registered</flux:table.column>
                    <flux:table.column>Due</flux:table.column>
                    <flux:table.column>Paid</flux:table.column>
                    <flux:table.column>Balance</flux:table.column>
                    @if ($canViewPaymentDetails)
                        <flux:table.column></flux:table.column>
                    @endif
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($schoolRows as $row)
                        @php $badge = $balanceBadge($row['balanceCents']); @endphp
                        <flux:table.row>
                            <flux:table.cell class="font-medium">{{ $row['school']->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                @forelse ($row['teachers'] as $teacher)
                                    @php $cell = \App\Support\Reports\TeacherDisplay::cellPhoneFormatted($teacher); @endphp
                                    <div @class(['mt-2' => ! $loop->first])>
                                        <div>{{ $teacher->user->name }}</div>
                                        @if ($teacher->user->email)
                                            <div class="mt-0.5 pl-3 text-xs text-zinc-500 dark:text-zinc-400">{{ $teacher->user->email }}</div>
                                        @endif
                                        @if ($cell)
                                            <div class="mt-0.5 pl-3 text-xs text-zinc-500 dark:text-zinc-400">{{ $cell }}</div>
                                        @endif
                                    </div>
                                @empty
                                    —
                                @endforelse
                            </flux:table.cell>
                            <flux:table.cell>{{ $row['count'] }}</flux:table.cell>
                            <flux:table.cell>${{ number_format($row['dueCents'] / 100, 2) }}</flux:table.cell>
                            <flux:table.cell>${{ number_format($row['paidCents'] / 100, 2) }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge color="{{ $badge['color'] }}" size="sm">{{ $badge['label'] }}</flux:badge>
                            </flux:table.cell>
                            @if ($canViewPaymentDetails)
                                <flux:table.cell align="end">
                                    @if ($row['paidCents'] !== 0 && $row['school'])
                                        <flux:button size="sm" variant="outline" icon="eye" wire:click="showPaymentDetails({{ $row['school']->id }})">Details</flux:button>
                                    @endif
                                </flux:table.cell>
                            @endif
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    {{-- Payment Details modal (Event Manager / Founder only) --}}
    @if ($canViewPaymentDetails)
        <flux:modal name="payment-details" class="w-full max-w-2xl">
            <div class="space-y-6">
                <div>
                    <flux:heading>Payment Details</flux:heading>
                    @if ($paymentDetailsRow)
                        <flux:text size="sm" class="text-zinc-500">{{ $paymentDetailsRow['school']->name ?? '—' }}</flux:text>
                    @endif
                </div>

                @if ($paymentDetailsRow)
                    @php
                        $payerName = function (\App\Models\PaymentTransaction $t): string {
                            return match ($t->source) {
                                \App\Enums\PaymentSource::CandidateEpayment => $t->payerStudent?->user->name ?? '—',
                                default => $t->payerTeacher?->user->name ?? '—',
                            };
                        };
                        $methodLabel = function (\App\Models\PaymentTransaction $t): string {
                            if ($t->source === \App\Enums\PaymentSource::Manual) {
                                return 'Manual'.($t->payment_type ? ' — '.$t->payment_type->label() : '');
                            }

                            return $t->source->label().($t->vendor ? ' ('.$t->vendor->label().')' : '');
                        };
                    @endphp

                    <div class="space-y-3 max-h-[28rem] overflow-y-auto">
                        @forelse ($paymentDetails as $entry)
                            @php $transaction = $entry['transaction']; @endphp
                            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="font-medium">{{ $payerName($transaction) }}</div>
                                        <div class="text-sm text-zinc-500 dark:text-zinc-400">
                                            {{ $methodLabel($transaction) }}
                                            @if ($transaction->paid_at)
                                                — {{ $transaction->paid_at->format('M j, Y') }}
                                            @endif
                                        </div>
                                        @if ($transaction->recordedBy)
                                            <div class="text-sm text-zinc-500 dark:text-zinc-400">Recorded by {{ $transaction->recordedBy->name }}</div>
                                        @endif
                                    </div>
                                    <div class="text-right">
                                        <div class="font-medium">${{ number_format($entry['amountCents'] / 100, 2) }}</div>
                                        @if ($transaction->amount !== $entry['amountCents'])
                                            <div class="text-xs text-zinc-500 dark:text-zinc-400">of ${{ number_format($transaction->amountInDollars(), 2) }} payment</div>
                                        @endif
                                    </div>
                                </div>

                                @php
                                    // A one-candidate payment needs no amount breakdown, and
                                    // no name at all when that candidate is the payer.
                                    $single = $entry['allocations']->count() === 1 ? $entry['allocations']->first() : null;
                                    $selfPaid = $single && $transaction->payer_student_id !== null
                                        && $single->candidate->student_id === $transaction->payer_student_id;
                                @endphp
                                @if ($single && ! $selfPaid)
                                    <div class="mt-1 pl-3 text-xs text-zinc-500 dark:text-zinc-400">
                                        for {{ $single->candidate->student->user->last_name }}, {{ $single->candidate->student->user->first_name }}
                                    </div>
                                @elseif (! $single)
                                    <div class="mt-2 space-y-0.5">
                                        @foreach ($entry['allocations'] as $allocation)
                                            <div class="flex justify-between gap-3 pl-3 text-xs text-zinc-500 dark:text-zinc-400">
                                                <span>{{ $allocation->candidate->student->user->last_name }}, {{ $allocation->candidate->student->user->first_name }}</span>
                                                <span>${{ number_format($allocation->amount / 100, 2) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @empty
                            <flux:text size="sm" class="text-zinc-500">No payments have been allocated to this school's candidates.</flux:text>
                        @endforelse
                    </div>

                    <div class="flex justify-between border-t border-zinc-200 dark:border-zinc-700 pt-3 font-medium">
                        <span>Total paid</span>
                        <span>${{ number_format($paymentDetailsRow['paidCents'] / 100, 2) }}</span>
                    </div>
                @endif

                <div class="flex justify-end">
                    <flux:modal.close>
                        <flux:button variant="ghost">Close</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        </flux:modal>
    @endif

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
