<div>
    <x-live-payments-sandbox-warning :version="$version" />

    @php $money = fn (int $cents): string => ($cents < 0 ? '-' : '').'$'.number_format(abs($cents) / 100, 2); @endphp

    <a href="{{ route('registrations.results', $version) }}" wire:navigate class="text-sm text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">&larr; Results</a>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mt-1 mb-6">
        <div>
            <flux:heading size="xl">Participation Fees</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->event->name }} — {{ $version->name }}</flux:text>
            @if ($window?->end_at)
                <flux:text size="sm" class="text-zinc-500">
                    Online payment {{ $version->participationFeeWindowHasClosed() ? 'closed' : 'closes' }} {{ $window->end_at->forDisplay()->format('M j, Y g:i A') }}
                </flux:text>
            @endif
        </div>
        <div class="flex gap-2">
            <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route('registrations.participation-fees-export', ['version' => $version, 'format' => 'pdf'])" target="_blank">PDF</flux:button>
            <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route('registrations.participation-fees-export', ['version' => $version, 'format' => 'csv'])">CSV</flux:button>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4 mb-6 lg:grid-cols-5">
        @foreach (['Due' => $totals['dueCents'], 'Waived' => $totals['waivedCents'], 'Paid' => $totals['paidCents'], 'Pending' => $totals['pendingCents'], 'Balance' => $totals['balanceCents']] as $label => $cents)
            <flux:card size="sm">
                <flux:text size="sm" class="text-zinc-500">{{ $label }}</flux:text>
                <flux:heading size="lg">{{ $money($cents) }}</flux:heading>
            </flux:card>
        @endforeach
    </div>

    @if ($unallocatedCents > 0)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-4">
            <flux:callout.text>
                {{ $money($unallocatedCents) }} you paid isn't tied to students yet, so it doesn't count toward anyone's balance.
                <a class="underline" href="{{ route('registrations.version', $version) }}" wire:navigate>Allocate it</a>.
            </flux:callout.text>
        </flux:callout>
    @endif

    @if ($confirming)
        <div wire:init="confirmPendingPayments" wire:poll.10s="confirmPendingPayments" class="mb-4">
            <flux:callout variant="secondary" icon="clock">
                <flux:callout.text>Your last payment is being confirmed. This usually takes a couple of minutes, so there's no need to pay again.</flux:callout.text>
            </flux:callout>
        </div>
    @endif

    @php
        $teacherReady = $version->epaymentTeacherReady();
        $opensLater = $teacherReady && ! $canPay && $window?->start_at?->isFuture();
        // Shown greyed out before payment opens, so it's clear where payment happens.
        $showPay = $teacherReady && ($canPay || $opensLater) && ! $confirming;
        $payDisabled = $canPay ? 'false' : 'true';
        $total = fn (string $ids, int $fee): string => '"$" + (($wire.'.$ids.'.length ? $wire.'.$ids.'.length * '.$fee.' + '.$surchargeCents.' : 0) / 100).toFixed(2)';
    @endphp

    @if ($opensLater)
        <flux:callout variant="warning" icon="clock" class="mb-4">
            <flux:callout.heading>Online payment opens {{ $window->start_at->forDisplay()->format('l, M j, Y') }} at {{ $window->start_at->forDisplay()->format('g:i A') }} Eastern</flux:callout.heading>
            <flux:callout.text>You can select students and pay once it opens. Check and cash payments can be recorded now: choose Manage.</flux:callout.text>
        </flux:callout>
    @elseif (! $teacherReady)
        <flux:callout variant="secondary" icon="information-circle" class="mb-4">
            <flux:callout.text>Online payment by teachers isn't turned on for this event. Check and cash payments can be recorded: choose Manage.</flux:callout.text>
        </flux:callout>
    @elseif (! $canPay)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-4">
            <flux:callout.heading>Online payment has closed</flux:callout.heading>
            <flux:callout.text>Check and cash payments can still be recorded: choose Manage.</flux:callout.text>
        </flux:callout>
    @endif

    @if ($rows->isEmpty())
        <flux:callout variant="secondary" icon="information-circle">
            <flux:callout.text>None of your students were accepted, so there are no Participation fees.</flux:callout.text>
        </flux:callout>
    @else
        @if ($showPay)
            <div class="flex flex-wrap items-center gap-2 mb-4">
                <flux:button size="sm" variant="ghost" wire:click="selectAllUnpaid" :disabled="! $canPay">Select all unpaid</flux:button>
                <flux:button size="sm" variant="primary" icon="credit-card" wire:click="payParticipation" x-bind:disabled="{{ $payDisabled }} || $wire.selectedCandidateIds.length === 0">
                    Pay <span x-text="{{ $total('selectedCandidateIds', $feeCents) }}"></span> with {{ $vendorLabel }} (<span x-text="$wire.selectedCandidateIds.length"></span> selected)
                </flux:button>
            </div>
        @endif

        {{-- Cards below md:, table at md:+ --}}
        <div class="md:hidden space-y-3">
            @foreach ($rows as $row)
                @php $candidate = $row['candidate']; @endphp
                <flux:card size="sm" wire:key="card-{{ $candidate->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-2">
                            @if ($showPay && $row['balanceCents'] > 0 && $row['pendingCents'] === 0)
                                <flux:checkbox wire:model="selectedCandidateIds" value="{{ $candidate->id }}" :disabled="! $canPay" class="mt-1" />
                            @endif
                            <div>
                                <flux:heading size="base">{{ $candidate->student->user->sort_name }}</flux:heading>
                                <flux:text size="sm" class="text-zinc-500">{{ $candidate->acceptedEnsemble->name ?? '—' }} &middot; {{ $candidate->school?->name }}</flux:text>
                            </div>
                        </div>
                        @include('livewire.registrations.partials.fee-balance-badge')
                    </div>
                    <div class="mt-2 text-sm text-zinc-500">Due {{ $money($row['dueCents']) }} &middot; Paid {{ $money($row['paidCents']) }}</div>
                    @include('livewire.registrations.partials.fee-payment-lines')
                    <flux:button size="sm" variant="ghost" class="mt-2" :href="route('registrations.candidate', [$version, $candidate])" wire:navigate>Manage</flux:button>
                </flux:card>
            @endforeach
        </div>

        <flux:table class="hidden md:table">
            <flux:table.columns>
                <flux:table.column></flux:table.column>
                <flux:table.column>Student</flux:table.column>
                <flux:table.column>Ensemble</flux:table.column>
                <flux:table.column>Due</flux:table.column>
                <flux:table.column>Paid</flux:table.column>
                <flux:table.column>Balance</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($rows as $row)
                    @php $candidate = $row['candidate']; @endphp
                    <flux:table.row wire:key="row-{{ $candidate->id }}">
                        <flux:table.cell>
                            @if ($showPay && $row['balanceCents'] > 0 && $row['pendingCents'] === 0)
                                <flux:checkbox wire:model="selectedCandidateIds" value="{{ $candidate->id }}" :disabled="! $canPay" />
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="font-medium">{{ $candidate->student->user->sort_name }}</div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $candidate->school?->name }}</div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $candidate->acceptedEnsemble->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $money($row['dueCents']) }}</flux:table.cell>
                        <flux:table.cell>
                            {{ $money($row['paidCents']) }}
                            @include('livewire.registrations.partials.fee-payment-lines')
                        </flux:table.cell>
                        <flux:table.cell>@include('livewire.registrations.partials.fee-balance-badge')</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" :href="route('registrations.candidate', [$version, $candidate])" wire:navigate>Manage</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        <flux:text size="sm" class="mt-2 text-zinc-500">To record a check or cash payment, or a refund, choose Manage, then Record Payment.</flux:text>
    @endif

    @if ($registrationRows->isNotEmpty())
        <flux:heading size="lg" class="mt-8">Registration fees still owed</flux:heading>
        <flux:text size="sm" class="mb-3 text-zinc-500">Separate from the Participation fee, and paid separately.</flux:text>

        @if ($showPay)
            <flux:button size="sm" variant="primary" icon="credit-card" class="mb-3" wire:click="payRegistration" x-bind:disabled="{{ $payDisabled }} || $wire.selectedRegistrationIds.length === 0">
                Pay <span x-text="{{ $total('selectedRegistrationIds', $registrationFeeCents) }}"></span> with {{ $vendorLabel }} (<span x-text="$wire.selectedRegistrationIds.length"></span> selected)
            </flux:button>
        @endif

        <div class="space-y-2">
            @foreach ($registrationRows as $row)
                @php $candidate = $row['candidate']; @endphp
                <flux:card size="sm" wire:key="reg-{{ $candidate->id }}">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            @if ($showPay && $row['pendingCents'] === 0)
                                <flux:checkbox wire:model="selectedRegistrationIds" value="{{ $candidate->id }}" :disabled="! $canPay" />
                            @endif
                            <a href="{{ route('registrations.candidate', [$version, $candidate]) }}" wire:navigate class="font-medium hover:underline">{{ $candidate->student->user->sort_name }}</a>
                        </div>
                        @include('livewire.registrations.partials.fee-balance-badge')
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif
</div>
