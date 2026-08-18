<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('registrations.index') }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Registrations</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>{{ $version->name }}</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">{{ $version->name }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->event->name }}</flux:text>
        </div>

        <div class="flex items-center gap-2">
            @php $vs = $version->getRawOriginal('status'); @endphp
            @if ($vs === 'active')
                <flux:badge color="green">Active</flux:badge>
            @elseif ($vs === 'sandbox')
                <flux:badge color="amber">Sandbox</flux:badge>
            @else
                <flux:badge color="zinc" class="capitalize">{{ $vs }}</flux:badge>
            @endif

            <flux:button id="tour-start" data-auto-start="{{ $showOrientation ? '1' : '0' }}" size="sm" variant="ghost" icon="sparkles" type="button">Take a tour</flux:button>
        </div>
    </div>

    {{-- Upcoming dates / Registration summary --}}
    @if ($upcomingDates->isNotEmpty() || $myCandidates->isNotEmpty())
        <div class="mb-6 pb-6 border-b border-zinc-200 dark:border-zinc-700 grid grid-cols-1 md:grid-cols-2 gap-6">
            @if ($upcomingDates->isNotEmpty())
                <div id="tour-upcoming-deadlines">
                    <flux:heading size="sm" class="mb-4">Upcoming Deadlines</flux:heading>
                    <div class="ml-1.5 space-y-5 border-l-2 border-zinc-200 dark:border-zinc-700">
                        @foreach ($upcomingDates as $date)
                            <div class="relative pl-5">
                                <span class="absolute -left-[7px] top-0.5 h-3 w-3 rounded-full bg-blue-500 ring-4 ring-white dark:ring-zinc-800"></span>
                                <div class="flex flex-wrap items-baseline gap-x-2">
                                    <span class="font-medium text-zinc-700 dark:text-zinc-300 text-sm">
                                        @if ($date->date_type === \App\Enums\VersionDateType::Candidate)
                                            StudentFolder.info dates
                                        @else
                                            {{ $date->date_type?->label() ?? $date->getRawOriginal('date_type') }}
                                        @endif
                                    </span>
                                    <span class="text-zinc-500 text-sm">
                                        {{ \Carbon\Carbon::parse($date->getRawOriginal('start_at'))->format('M j, Y') }}
                                        @if ($date->getRawOriginal('end_at'))
                                            – {{ \Carbon\Carbon::parse($date->getRawOriginal('end_at'))->format('M j, Y') }}
                                        @endif
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($myCandidates->isNotEmpty())
                <div id="tour-registration-summary">
                    <flux:heading size="sm" class="mb-4">Registration Summary</flux:heading>
                    <div class="space-y-4">
                        <flux:table class="min-w-0 w-fit">
                            <flux:table.columns>
                                @foreach ($voicePartCounts as $row)
                                    <flux:table.column>{{ $row['label'] }}</flux:table.column>
                                @endforeach
                                <flux:table.column>Total</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                <flux:table.row>
                                    @foreach ($voicePartCounts as $row)
                                        <flux:table.cell>{{ $row['count'] }}</flux:table.cell>
                                    @endforeach
                                    <flux:table.cell class="font-semibold">{{ $voicePartTotal }}</flux:table.cell>
                                </flux:table.row>
                            </flux:table.rows>
                        </flux:table>

                        <flux:table class="min-w-0 w-fit">
                            <flux:table.columns>
                                @foreach ($statusCounts as $row)
                                    <flux:table.column align="center" class="!px-4">{{ $row['label'] }}</flux:table.column>
                                @endforeach
                                <flux:table.column align="center" class="!px-4">Total</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                <flux:table.row>
                                    @foreach ($statusCounts as $row)
                                        <flux:table.cell align="center">{{ $row['count'] }}</flux:table.cell>
                                    @endforeach
                                    <flux:table.cell align="center" class="font-semibold">{{ $statusTotal }}</flux:table.cell>
                                </flux:table.row>
                            </flux:table.rows>
                        </flux:table>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- Candidates --}}
    <div class="mb-8">
        <flux:heading size="lg" class="mb-3">
            My Candidates
            @if ($myCandidates->isNotEmpty())
                <flux:badge color="zinc" size="sm" class="ml-2">{{ $myCandidates->count() }}</flux:badge>
            @endif
        </flux:heading>

        <div class="rounded-lg border border-blue-200 bg-blue-50 dark:border-blue-900 dark:bg-blue-950/40 px-4 py-3 mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            @if ($epaymentStudentEnabled)
                <div id="tour-epayment-checkbox">
                    <flux:checkbox
                        :checked="$epaymentOptedIn"
                        wire:click="toggleEpaymentOptIn"
                        label="Enable e-payment for all of your candidates in this Version"
                        description="Applies to your whole roster, not just one candidate."
                    />
                </div>
            @endif

            <div class="flex flex-wrap gap-2">
                <flux:button id="tour-link-pitch-files" size="sm" variant="ghost" icon="musical-note" :href="route('registrations.pitch-files', $version)" wire:navigate>Pitch Files</flux:button>
                <flux:button id="tour-link-estimate-form" size="sm" variant="ghost" icon="document-text" :href="route('registrations.estimate-form', $version)" wire:navigate>Estimate Form</flux:button>
                <flux:button
                    id="tour-link-group-payment"
                    size="sm" variant="ghost" icon="user-group"
                    x-on:click="
                        const el = document.getElementById('group-payment');
                        if (! el) return;
                        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        el.classList.add('ring-2', 'ring-blue-400', 'rounded-lg');
                        setTimeout(() => el.classList.remove('ring-2', 'ring-blue-400', 'rounded-lg'), 1500);
                    "
                >Group Payment</flux:button>
                <flux:button id="tour-link-payment-register" size="sm" variant="ghost" icon="table-cells" wire:click="openPaymentRegister">Payment Register</flux:button>
            </div>
        </div>

        @if ($myCandidates->isEmpty())
            <flux:text class="text-zinc-500">No candidates yet. Eligible students are enrolled automatically once you're invited and once they're added to your roster.</flux:text>
        @else
            <div class="flex flex-col sm:flex-row gap-3 mb-4">
                <div id="tour-search-box">
                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search by name..."
                        icon="magnifying-glass"
                        class="sm:max-w-xs"
                    />
                </div>
                <div id="tour-filters" class="flex flex-col sm:flex-row gap-3">
                    <flux:select wire:model.live="voicePartFilter" placeholder="All voice parts" class="sm:max-w-2xs">
                        <flux:select.option value="">All voice parts</flux:select.option>
                        @foreach ($voiceParts as $voicePart)
                            <flux:select.option value="{{ $voicePart->id }}">{{ $voicePart->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="statusFilter" placeholder="All statuses" class="sm:max-w-2xs">
                        <flux:select.option value="">All statuses</flux:select.option>
                        @foreach ($statusOptions as $status)
                            <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>

            {{-- Your Unreconciled Payments + Group Payment selection — moved
                 to the top of the table (not the bottom of the page) so a
                 teacher who just paid immediately sees there's a second
                 step, rather than having to scroll past the whole roster to
                 find it. Placed outside the search/filter empty-state check
                 below so an unrelated search/filter can't hide it. --}}
            <div id="group-payment" class="mb-4 space-y-4">
                @if ($unreconciledPayments->isNotEmpty())
                    <div class="rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40 p-4">
                        <flux:heading size="xs" class="text-amber-800 dark:text-amber-300 mb-1">Your Unreconciled Payments</flux:heading>
                        <flux:text size="sm" class="text-amber-700 dark:text-amber-400 mb-3">
                            These payments haven't been allocated to specific candidates yet, so they don't count toward
                            anyone's balance until you allocate them.
                        </flux:text>

                        <div class="space-y-3">
                            @foreach ($unreconciledPayments as $payment)
                                <flux:card size="sm">
                                    <div class="flex items-center justify-between gap-3">
                                        <div>
                                            <span class="font-medium">${{ number_format($payment->amountInDollars(), 2) }}</span>
                                            <span class="text-zinc-500"> total</span>
                                            @if ($payment->paid_at)
                                                <span class="text-zinc-500"> — {{ $payment->paid_at->format('M j, Y') }}</span>
                                            @endif
                                            <div class="text-sm text-amber-600 dark:text-amber-400">
                                                ${{ number_format($payment->unallocatedAmount() / 100, 2) }} still unallocated
                                            </div>
                                            @if ($payment->reference_number)
                                                <div class="text-sm text-zinc-500">Ref: {{ $payment->reference_number }}</div>
                                            @endif
                                        </div>
                                        <flux:button size="sm" variant="primary" wire:click="openAllocate({{ $payment->id }})">
                                            Allocate
                                        </flux:button>
                                    </div>
                                </flux:card>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($activeFeeTypes->isNotEmpty())
                    <div class="flex items-center justify-between">
                        <div>
                            <flux:heading size="xs" class="text-zinc-500">Group Payment</flux:heading>
                            <flux:text size="sm" class="text-zinc-500">{{ count($selectedCandidateIds) }} selected</flux:text>
                        </div>
                        @if (count($selectedCandidateIds) > 0)
                            {{-- Usually just one button — participation and
                                 housing can both be active at once once the
                                 Version closes, so up to two show side by side. --}}
                            <div class="flex items-center gap-2">
                                @foreach ($activeFeeTypes as $feeType)
                                    <flux:button size="sm" variant="primary" icon="credit-card" wire:click="payForSelected('{{ $feeType->value }}')">
                                        Pay {{ $feeType->label() }} for Selected
                                    </flux:button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @else
                    <flux:text size="sm" class="text-zinc-500">
                        Group Payment is not available right now — no registration, participation, or housing fee window is currently open.
                    </flux:text>
                @endif
            </div>

            @if ($filteredCandidates->isEmpty())
                <flux:text class="text-zinc-500">No candidates match your search/filters.</flux:text>
            @else
            {{-- Cards below md:, table at md:+ --}}
            <div class="md:hidden space-y-3">
                @foreach ($filteredCandidates as $candidate)
                    @php
                        $rawStatus = $candidate->getRawOriginal('status');
                        $allDone = collect($checklistDefs)->every(fn ($def) => ($def['check'])($candidate));
                        $studentUser = $candidate->student->user;
                        $displayName = $studentUser->last_name.', '.trim($studentUser->first_name.' '.$studentUser->middle_name);
                    @endphp
                    <flux:card size="sm">
                        <div class="flex items-start justify-between gap-3 mb-3">
                            <div class="flex items-start gap-2">
                                @if ($activeFeeTypes->isNotEmpty() && in_array($candidate->status, $feeEligibleStatuses, true))
                                    <flux:checkbox wire:model.live="selectedCandidateIds" value="{{ $candidate->id }}" class="mt-1" />
                                @endif
                                <flux:heading size="base">{{ $displayName }}</flux:heading>
                            </div>
                            <div class="flex flex-col items-end gap-1">
                                <div id="{{ $loop->first ? 'tour-row-status-mobile' : '' }}">
                                    @if ($rawStatus === 'eligible')
                                        <flux:badge color="zinc" size="sm">Eligible</flux:badge>
                                    @elseif ($rawStatus === 'pending')
                                        <flux:badge color="amber" size="sm">Pending</flux:badge>
                                    @elseif ($rawStatus === 'registered')
                                        <flux:badge color="green" size="sm">Registered</flux:badge>
                                    @elseif ($rawStatus === 'teacher_withdrawn')
                                        <flux:badge color="red" size="sm">Withdrawn</flux:badge>
                                    @else
                                        <flux:badge color="zinc" size="sm" class="capitalize">{{ str_replace('_', ' ', $rawStatus) }}</flux:badge>
                                    @endif
                                </div>
                                @php $paidCents = $paidByCandidateId->get($candidate->id, 0); @endphp
                                <span id="{{ $loop->first ? 'tour-row-paid-mobile' : '' }}" class="text-sm text-zinc-500">
                                    Paid: {{ $paidCents < 0 ? '-' : '' }}${{ number_format(abs($paidCents) / 100, 2) }}
                                </span>
                            </div>
                        </div>

                        {{-- Poka-yoke checklist --}}
                        <div id="{{ $loop->first ? 'tour-row-checklist-mobile' : '' }}" class="flex flex-wrap gap-2 mb-3">
                            @foreach ($checklistDefs as $def)
                                @php
                                    $done = ($def['check'])($candidate);
                                    $partial = ! $done && isset($def['partial']) && ($def['partial'])($candidate);
                                @endphp
                                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium
                                    {{ $done ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400' : ($partial ? 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' : 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400') }}">
                                    @if ($done)
                                        <flux:icon.check-circle variant="micro" />
                                    @elseif ($partial)
                                        <flux:icon.minus-circle variant="micro" />
                                    @else
                                        <flux:icon.x-circle variant="micro" />
                                    @endif
                                    {{ $def['label'] }}
                                </span>
                            @endforeach
                        </div>

                        <div class="flex gap-2">
                            <flux:button id="{{ $loop->first ? 'tour-row-manage-mobile' : '' }}" size="sm" variant="ghost"
                                :href="route('registrations.candidate', [$version, $candidate])"
                                wire:navigate>
                                Manage
                            </flux:button>
                            @if (in_array($rawStatus, ['eligible', 'pending', 'registered']))
                                <flux:button id="{{ $loop->first ? 'tour-row-refresh-mobile' : '' }}" size="sm" variant="ghost" icon="arrow-path"
                                    wire:click="refreshStatus({{ $candidate->id }})">
                                    Refresh
                                </flux:button>
                            @endif
                            <flux:button id="{{ $loop->first ? 'tour-row-withdraw-mobile' : '' }}" size="sm" variant="ghost"
                                wire:click="withdraw({{ $candidate->id }})"
                                wire:confirm="Withdraw {{ $candidate->program_name }}? Their status will be set to Teacher Withdrawn.">
                                Withdraw
                            </flux:button>
                        </div>
                    </flux:card>
                @endforeach
            </div>

            <flux:table class="hidden md:table">
                <flux:table.columns>
                    @if ($activeFeeTypes->isNotEmpty())
                        <flux:table.column></flux:table.column>
                    @endif
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Voice Part</flux:table.column>
                    <flux:table.column>Checklist</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Paid</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($filteredCandidates as $candidate)
                        @php
                            $rawStatus = $candidate->getRawOriginal('status');
                            $allDone = collect($checklistDefs)->every(fn ($def) => ($def['check'])($candidate));
                            $studentUser = $candidate->student->user;
                            $displayName = $studentUser->last_name.', '.trim($studentUser->first_name.' '.$studentUser->middle_name);
                        @endphp
                        <flux:table.row>
                            @if ($activeFeeTypes->isNotEmpty())
                                <flux:table.cell>
                                    @if (in_array($candidate->status, $feeEligibleStatuses, true))
                                        <flux:checkbox wire:model.live="selectedCandidateIds" value="{{ $candidate->id }}" />
                                    @endif
                                </flux:table.cell>
                            @endif
                            <flux:table.cell class="font-medium">{{ $displayName }}</flux:table.cell>
                            <flux:table.cell>{{ $candidate->voicePart?->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell id="{{ $loop->first ? 'tour-row-checklist' : '' }}">
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($checklistDefs as $def)
                                        @php
                                            $done = ($def['check'])($candidate);
                                            $partial = ! $done && isset($def['partial']) && ($def['partial'])($candidate);
                                        @endphp
                                        <span class="inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-medium
                                            {{ $done ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400' : ($partial ? 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' : 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400') }}">
                                            @if ($done)
                                                <flux:icon.check-circle variant="micro" />
                                            @elseif ($partial)
                                                <flux:icon.minus-circle variant="micro" />
                                            @else
                                                <flux:icon.x-circle variant="micro" />
                                            @endif
                                            {{ $def['label'] }}
                                        </span>
                                    @endforeach
                                </div>
                            </flux:table.cell>
                            <flux:table.cell id="{{ $loop->first ? 'tour-row-status' : '' }}">
                                @if ($rawStatus === 'eligible')
                                    <flux:badge color="zinc" size="sm">Eligible</flux:badge>
                                @elseif ($rawStatus === 'pending')
                                    <flux:badge color="amber" size="sm">Pending</flux:badge>
                                @elseif ($rawStatus === 'registered')
                                    <flux:badge color="green" size="sm">Registered</flux:badge>
                                @elseif ($rawStatus === 'teacher_withdrawn')
                                    <flux:badge color="red" size="sm">Withdrawn</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm" class="capitalize">{{ str_replace('_', ' ', $rawStatus) }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell id="{{ $loop->first ? 'tour-row-paid' : '' }}">
                                @php $paidCents = $paidByCandidateId->get($candidate->id, 0); @endphp
                                {{ $paidCents < 0 ? '-' : '' }}${{ number_format(abs($paidCents) / 100, 2) }}
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex justify-end gap-2">
                                    <flux:button id="{{ $loop->first ? 'tour-row-manage' : '' }}" size="sm" variant="ghost"
                                        :href="route('registrations.candidate', [$version, $candidate])"
                                        wire:navigate>
                                        Manage
                                    </flux:button>
                                    @if (in_array($rawStatus, ['eligible', 'pending', 'registered']))
                                        <flux:button id="{{ $loop->first ? 'tour-row-refresh' : '' }}" size="sm" variant="ghost" icon="arrow-path"
                                            wire:click="refreshStatus({{ $candidate->id }})">
                                            Refresh
                                        </flux:button>
                                    @endif
                                    <flux:button id="{{ $loop->first ? 'tour-row-withdraw' : '' }}" size="sm" variant="ghost"
                                        wire:click="withdraw({{ $candidate->id }})"
                                        wire:confirm="Withdraw {{ $candidate->program_name }}? Their status will be set to Teacher Withdrawn.">
                                        Withdraw
                                    </flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
            @endif
        @endif
    </div>

    {{-- Allocate Payment modal --}}
    <flux:modal name="allocate-payment" class="w-full max-w-lg">
        <div class="space-y-6">
            <flux:heading>Allocate Payment</flux:heading>

            @php $allocatingPayment = $unreconciledPayments->firstWhere('id', $allocatingTransactionId); @endphp

            {{-- Candidate list only renders while a payment is actively
                 being allocated — always rendering the full roster here
                 (even hidden behind the closed modal) would leak every
                 candidate's name into the page source regardless of the
                 search/voice-part/status filters above. --}}
            @if ($allocatingPayment)
                <flux:text size="sm" class="text-zinc-500">
                    ${{ number_format($allocatingPayment->unallocatedAmount() / 100, 2) }} remaining to allocate out
                    of ${{ number_format($allocatingPayment->amountInDollars(), 2) }} total.
                </flux:text>

                <flux:error name="allocationAmounts" />

                <div class="space-y-3 max-h-80 overflow-y-auto">
                    @foreach ($myCandidates as $candidate)
                        <div class="flex items-center justify-between gap-3">
                            <flux:text size="sm">{{ $candidate->student->user->last_name }}, {{ $candidate->student->user->first_name }}</flux:text>
                            <flux:input
                                wire:model="allocationAmounts.{{ $candidate->id }}"
                                type="number" step="0.01" min="0" placeholder="0.00"
                                class="max-w-32"
                            />
                        </div>
                    @endforeach
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

    {{-- Payment Register modal — every payment_allocations row across this
         teacher's own roster in this Version, manual and e-payment alike,
         ordered by candidate sort name then payment chronology. CSV/PDF
         links reuse the exact same rows via VersionDashboard::paymentRegisterRows(). --}}
    <flux:modal name="payment-register" class="w-full max-w-3xl">
        <div class="space-y-4">
            <div class="flex items-center justify-between gap-3">
                <flux:heading>Payment Register</flux:heading>
                <div class="flex gap-2">
                    <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('registrations.payment-register-csv', $version)" target="_blank">
                        CSV
                    </flux:button>
                    <flux:button size="sm" variant="ghost" icon="printer" :href="route('registrations.payment-register-pdf', $version)" target="_blank">
                        PDF
                    </flux:button>
                </div>
            </div>

            @if ($paymentRegisterRows->isEmpty())
                <flux:text class="text-zinc-500">No payments recorded yet.</flux:text>
            @else
                <div class="max-h-96 overflow-y-auto">
                    {{-- Cards below md:, full table at md:+ --}}
                    <div class="md:hidden space-y-3">
                        @foreach ($paymentRegisterRows as $row)
                            <flux:card size="sm">
                                <div class="flex items-center justify-between gap-3 mb-1">
                                    <flux:heading size="base">{{ $row['candidate']->student->user->sort_name }}</flux:heading>
                                    <span class="font-medium">{{ $row['amountCents'] < 0 ? '-' : '' }}${{ number_format(abs($row['amountCents']) / 100, 2) }}</span>
                                </div>
                                <div class="text-sm text-zinc-500">{{ $row['paidAt']->format('M j, Y') }} &middot; {{ $row['type'] }}</div>
                                <div class="text-sm text-zinc-500">
                                    {{ $row['referenceNumber'] ?? 'No reference' }} &middot; {{ $row['status']->label() }}
                                </div>
                            </flux:card>
                        @endforeach
                    </div>

                    <flux:table class="hidden md:table">
                        <flux:table.columns>
                            <flux:table.column>Candidate</flux:table.column>
                            <flux:table.column>Date</flux:table.column>
                            <flux:table.column>Type</flux:table.column>
                            <flux:table.column>Amount</flux:table.column>
                            <flux:table.column>Reference</flux:table.column>
                            <flux:table.column>Status</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($paymentRegisterRows as $row)
                                <flux:table.row>
                                    <flux:table.cell class="font-medium">{{ $row['candidate']->student->user->sort_name }}</flux:table.cell>
                                    <flux:table.cell>{{ $row['paidAt']->format('M j, Y') }}</flux:table.cell>
                                    <flux:table.cell>{{ $row['type'] }}</flux:table.cell>
                                    <flux:table.cell>{{ $row['amountCents'] < 0 ? '-' : '' }}${{ number_format(abs($row['amountCents']) / 100, 2) }}</flux:table.cell>
                                    <flux:table.cell>{{ $row['referenceNumber'] ?? '—' }}</flux:table.cell>
                                    <flux:table.cell>{{ $row['status']->label() }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost">Close</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>

    {{-- Spotlight tour (event-version-orientation.md §6.2 orientation pass).
         Fires the Livewire action below via a hidden wire:click trigger
         rather than calling $wire directly from plain <script>, so it reuses
         the same delegated-click plumbing every other wire:click in this
         file already relies on. --}}
    <button type="button" id="tour-dismiss-trigger" wire:click="dismissOrientation" class="hidden" aria-hidden="true" tabindex="-1"></button>

    <div id="tour-scrim" class="hidden fixed inset-0 z-[59]"></div>
    <div id="tour-cutout" class="hidden fixed z-[60] rounded-lg pointer-events-none transition-[top,left,width,height] duration-300 ease-out"></div>
    <div
        id="tour-card"
        class="hidden fixed z-[61] w-72 max-w-[calc(100vw-2rem)] bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg shadow-xl p-4 transition-[top,left] duration-300 ease-out"
        role="dialog" aria-modal="true" aria-labelledby="tour-title" aria-describedby="tour-body"
    >
        <div class="h-1 rounded-full bg-zinc-100 dark:bg-zinc-700 overflow-hidden mb-3">
            <div id="tour-progress" class="h-full bg-orange-600 dark:bg-orange-400 rounded-full transition-[width] duration-200"></div>
        </div>
        <div id="tour-stepcount" class="text-[11px] font-semibold uppercase tracking-wide text-orange-600 dark:text-orange-400 mb-1"></div>
        <h3 id="tour-title" class="text-sm font-semibold text-zinc-800 dark:text-zinc-100 mb-1"></h3>
        <p id="tour-body" class="text-sm text-zinc-500 dark:text-zinc-400 mb-3"></p>
        <div class="flex items-center justify-between gap-2">
            <button type="button" id="tour-skip" class="text-sm text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200">Skip tour</button>
            <div class="flex gap-2">
                <button type="button" id="tour-prev" class="text-sm font-medium px-3 py-1.5 rounded-md border border-zinc-200 dark:border-zinc-700 text-zinc-700 dark:text-zinc-200 hover:bg-zinc-50 dark:hover:bg-zinc-700 disabled:opacity-40">Back</button>
                <button type="button" id="tour-next" class="text-sm font-medium px-3 py-1.5 rounded-md border border-orange-600 bg-orange-600 text-white hover:brightness-110 dark:border-orange-400 dark:bg-orange-400 dark:text-zinc-900">Next</button>
            </div>
        </div>
    </div>

    <style>
        #tour-cutout { box-shadow: 0 0 0 9999px rgba(15, 13, 12, 0.6); }
        :root[data-theme="dark"] #tour-cutout { box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.72); }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) #tour-cutout { box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.72); }
        }
        #tour-cutout::after {
            content: "";
            position: absolute;
            inset: -4px;
            border-radius: 11px;
            border: 2px solid rgb(234 88 12);
            box-shadow: 0 0 0 4px rgba(234, 88, 12, 0.5);
            animation: tour-pulse 1.8s ease-in-out infinite;
        }
        @keyframes tour-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.45; }
        }
        @media (prefers-reduced-motion: reduce) {
            #tour-cutout, #tour-card { transition: none !important; }
            #tour-cutout::after { animation: none !important; }
        }
    </style>

    <script>
        (function () {
                var steps = [
                    { ids: ['tour-upcoming-deadlines'], title: 'Upcoming Deadlines', body: "What's due next for the Event — registration windows, postmark cutoffs, adjudication dates — soonest first." },
                    { ids: ['tour-registration-summary'], title: 'Registration Summary', body: 'A live count of your registered candidates by voice part and by status, so you can see progress at a glance without scrolling the table.' },
                    { ids: ['tour-epayment-checkbox'], title: 'E-payment checkbox', body: 'Turn on electronic payment for every candidate on your roster.' },
                    { ids: ['tour-link-pitch-files'], title: 'Pitch Files', body: 'Find audio and PDFs for the Event, filterable by voice part — the same library your candidates can browse.' },
                    { ids: ['tour-link-estimate-form'], title: 'Estimate Form', body: "Download a printable PDF invoice for one of your schools: every registered candidate, their fee, and what's still owed." },
                    { ids: ['tour-link-group-payment'], title: 'Group Payment', body: 'Select several candidates in the roster below and pay their registration or participation fee in a single checkout.' },
                    { ids: ['tour-link-payment-register'], title: 'Payment Register', body: 'A full history of every payment recorded against your roster — manual and electronic alike.' },
                    { ids: ['tour-search-box'], title: 'Search box', body: 'Find one candidate by name without scrolling the full roster.' },
                    { ids: ['tour-filters'], title: 'Filters', body: 'Narrow the roster down to one voice part or one status at a time.' },
                    { ids: ['tour-row-checklist', 'tour-row-checklist-mobile'], title: 'Checklist', body: 'See the status of registration requirements — birthday, emergency contact, application, and so on. Green means done, amber means partial, red means not started.' },
                    { ids: ['tour-row-status', 'tour-row-status-mobile'], title: 'Status', body: 'Where the candidate sits in the registration lifecycle: Eligible, Pending, Registered, or Withdrawn.' },
                    { ids: ['tour-row-paid', 'tour-row-paid-mobile'], title: 'Paid', body: 'How much has actually been allocated to this candidate so far, net of any refunds.' },
                    { ids: ['tour-row-manage', 'tour-row-manage-mobile'], title: 'Manage', body: "Open this candidate's full record to edit details, review their application, or record a payment." },
                    { ids: ['tour-row-refresh', 'tour-row-refresh-mobile'], title: 'Refresh', body: "Recheck this candidate's status against the checklist — handy right after you fix something that was missing." },
                    { ids: ['tour-row-withdraw', 'tour-row-withdraw-mobile'], title: 'Withdraw', body: 'Remove this candidate from the Version. Their status becomes Teacher Withdrawn.' }
                ];

                var activeSteps = [];
                var current = -1;
                var running = false;
                var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                var raf = null;

                var scrim = document.getElementById('tour-scrim');
                var cutout = document.getElementById('tour-cutout');
                var card = document.getElementById('tour-card');
                var startBtn = document.getElementById('tour-start');
                var dismissTrigger = document.getElementById('tour-dismiss-trigger');
                var prevBtn = document.getElementById('tour-prev');
                var nextBtn = document.getElementById('tour-next');
                var skipBtn = document.getElementById('tour-skip');
                var titleEl = document.getElementById('tour-title');
                var bodyEl = document.getElementById('tour-body');
                var stepCountEl = document.getElementById('tour-stepcount');
                var progressEl = document.getElementById('tour-progress');

                if (!startBtn || !scrim || !cutout || !card) return;

                function resolveEl(ids) {
                    for (var i = 0; i < ids.length; i++) {
                        var el = document.getElementById(ids[i]);
                        if (el && el.offsetParent !== null) return el;
                    }
                    return null;
                }

                function start() {
                    activeSteps = steps.filter(function (s) { return resolveEl(s.ids) !== null; });
                    if (activeSteps.length === 0) return;

                    running = true;
                    current = 0;
                    scrim.classList.remove('hidden');
                    cutout.classList.remove('hidden');
                    card.classList.remove('hidden');
                    document.addEventListener('keydown', onKeydown);
                    window.addEventListener('resize', onReposition);
                    window.addEventListener('scroll', onReposition, true);
                    render();
                }

                function end() {
                    running = false;
                    scrim.classList.add('hidden');
                    cutout.classList.add('hidden');
                    card.classList.add('hidden');
                    document.removeEventListener('keydown', onKeydown);
                    window.removeEventListener('resize', onReposition);
                    window.removeEventListener('scroll', onReposition, true);
                    if (dismissTrigger) dismissTrigger.click();
                    startBtn.focus();
                }

                function go(delta) {
                    var target = current + delta;
                    if (target < 0) return;
                    if (target >= activeSteps.length) { end(); return; }
                    current = target;
                    render();
                }

                function render() {
                    var step = activeSteps[current];
                    var el = resolveEl(step.ids);
                    if (!el) { go(1); return; }

                    titleEl.textContent = step.title;
                    bodyEl.textContent = step.body;
                    stepCountEl.textContent = 'Step ' + (current + 1) + ' of ' + activeSteps.length;
                    progressEl.style.width = (((current + 1) / activeSteps.length) * 100) + '%';
                    prevBtn.disabled = current === 0;
                    nextBtn.textContent = current === activeSteps.length - 1 ? 'Finish' : 'Next';

                    el.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });

                    window.setTimeout(function () { position(el); }, reduceMotion ? 0 : 260);
                    nextBtn.focus();
                }

                function position(el) {
                    var pad = 6;
                    var r = el.getBoundingClientRect();

                    cutout.style.top = (r.top - pad) + 'px';
                    cutout.style.left = (r.left - pad) + 'px';
                    cutout.style.width = (r.width + pad * 2) + 'px';
                    cutout.style.height = (r.height + pad * 2) + 'px';

                    var cardW = card.offsetWidth || 288;
                    var cardH = card.offsetHeight || 160;
                    var margin = 14;
                    var vw = window.innerWidth;
                    var vh = window.innerHeight;

                    var top = r.bottom + margin;
                    if (top + cardH > vh) {
                        top = r.top - cardH - margin;
                        if (top < 8) top = Math.max(8, Math.min(vh - cardH - 8, r.top));
                    }

                    var left = r.left;
                    if (left + cardW > vw - 8) left = vw - cardW - 8;
                    if (left < 8) left = 8;

                    card.style.top = top + 'px';
                    card.style.left = left + 'px';
                }

                function onReposition() {
                    if (!running) return;
                    if (raf) cancelAnimationFrame(raf);
                    raf = requestAnimationFrame(function () {
                        var el = resolveEl(activeSteps[current].ids);
                        if (el) position(el);
                    });
                }

                function onKeydown(e) {
                    if (e.key === 'Escape') { end(); return; }
                    if (e.key === 'ArrowRight' || e.key === 'Enter') { go(1); return; }
                    if (e.key === 'ArrowLeft') { go(-1); return; }
                }

                startBtn.addEventListener('click', start);
                nextBtn.addEventListener('click', function () { go(1); });
                prevBtn.addEventListener('click', function () { go(-1); });
                skipBtn.addEventListener('click', end);

                if (startBtn.dataset.autoStart === '1') start();
        })();
    </script>
</div>
