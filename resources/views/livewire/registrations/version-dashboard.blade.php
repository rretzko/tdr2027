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

        @php $vs = $version->getRawOriginal('status'); @endphp
        @if ($vs === 'active')
            <flux:badge color="green">Active</flux:badge>
        @elseif ($vs === 'sandbox')
            <flux:badge color="amber">Sandbox</flux:badge>
        @else
            <flux:badge color="zinc" class="capitalize">{{ $vs }}</flux:badge>
        @endif
    </div>

    {{-- Upcoming dates / Registration summary --}}
    @if ($upcomingDates->isNotEmpty() || $myCandidates->isNotEmpty())
        <div class="mb-6 pb-6 border-b border-zinc-200 dark:border-zinc-700 grid grid-cols-1 md:grid-cols-2 gap-6">
            @if ($upcomingDates->isNotEmpty())
                <div>
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
                <div>
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
                <flux:checkbox
                    :checked="$epaymentOptedIn"
                    wire:click="toggleEpaymentOptIn"
                    label="Enable e-payment for all of your candidates in this Version"
                    description="Applies to your whole roster, not just one candidate."
                />
            @endif

            <div class="flex flex-wrap gap-2">
                <flux:button size="sm" variant="ghost" icon="table-cells" wire:click="openPaymentRegister">Payment Register</flux:button>
                <flux:button size="sm" variant="ghost" icon="document-text" :href="route('registrations.estimate-form', $version)" wire:navigate>Estimate Form</flux:button>
                <flux:button
                    size="sm" variant="ghost" icon="user-group"
                    x-on:click="
                        const el = document.getElementById('group-payment');
                        if (! el) return;
                        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        el.classList.add('ring-2', 'ring-blue-400', 'rounded-lg');
                        setTimeout(() => el.classList.remove('ring-2', 'ring-blue-400', 'rounded-lg'), 1500);
                    "
                >Group Payment</flux:button>
            </div>
        </div>

        @if ($myCandidates->isEmpty())
            <flux:text class="text-zinc-500">No candidates yet. Eligible students are enrolled automatically once you're invited and once they're added to your roster.</flux:text>
        @else
            <div class="flex flex-col sm:flex-row gap-3 mb-4">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search by name..."
                    icon="magnifying-glass"
                    class="sm:max-w-xs"
                />
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

                @if ($activeFeeType !== null)
                    <div class="flex items-center justify-between">
                        <div>
                            <flux:heading size="xs" class="text-zinc-500">Group Payment</flux:heading>
                            <flux:text size="sm" class="text-zinc-500">{{ count($selectedCandidateIds) }} selected</flux:text>
                        </div>
                        @if (count($selectedCandidateIds) > 0)
                            <flux:button size="sm" variant="primary" icon="credit-card" wire:click="payForSelected('{{ $activeFeeType->value }}')">
                                Pay {{ $activeFeeType->label() }} for Selected
                            </flux:button>
                        @endif
                    </div>
                @else
                    <flux:text size="sm" class="text-zinc-500">
                        Group Payment is not available right now — no registration or participation fee window is currently open.
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
                                @if ($activeFeeType !== null && in_array($candidate->status, $feeEligibleStatuses, true))
                                    <flux:checkbox wire:model.live="selectedCandidateIds" value="{{ $candidate->id }}" class="mt-1" />
                                @endif
                                <flux:heading size="base">{{ $displayName }}</flux:heading>
                            </div>
                            <div class="flex flex-col items-end gap-1">
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
                                @php $paidCents = $paidByCandidateId->get($candidate->id, 0); @endphp
                                <span class="text-sm text-zinc-500">
                                    Paid: {{ $paidCents < 0 ? '-' : '' }}${{ number_format(abs($paidCents) / 100, 2) }}
                                </span>
                            </div>
                        </div>

                        {{-- Poka-yoke checklist --}}
                        <div class="flex flex-wrap gap-2 mb-3">
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
                            <flux:button size="sm" variant="ghost"
                                :href="route('registrations.candidate', [$version, $candidate])"
                                wire:navigate>
                                Manage
                            </flux:button>
                            @if (in_array($rawStatus, ['eligible', 'pending', 'registered']))
                                <flux:button size="sm" variant="ghost" icon="arrow-path"
                                    wire:click="refreshStatus({{ $candidate->id }})">
                                    Refresh
                                </flux:button>
                            @endif
                            <flux:button size="sm" variant="ghost"
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
                    @if ($activeFeeType !== null)
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
                            @if ($activeFeeType !== null)
                                <flux:table.cell>
                                    @if (in_array($candidate->status, $feeEligibleStatuses, true))
                                        <flux:checkbox wire:model.live="selectedCandidateIds" value="{{ $candidate->id }}" />
                                    @endif
                                </flux:table.cell>
                            @endif
                            <flux:table.cell class="font-medium">{{ $displayName }}</flux:table.cell>
                            <flux:table.cell>{{ $candidate->voicePart?->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
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
                            <flux:table.cell>
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
                            <flux:table.cell>
                                @php $paidCents = $paidByCandidateId->get($candidate->id, 0); @endphp
                                {{ $paidCents < 0 ? '-' : '' }}${{ number_format(abs($paidCents) / 100, 2) }}
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" variant="ghost"
                                        :href="route('registrations.candidate', [$version, $candidate])"
                                        wire:navigate>
                                        Manage
                                    </flux:button>
                                    @if (in_array($rawStatus, ['eligible', 'pending', 'registered']))
                                        <flux:button size="sm" variant="ghost" icon="arrow-path"
                                            wire:click="refreshStatus({{ $candidate->id }})">
                                            Refresh
                                        </flux:button>
                                    @endif
                                    <flux:button size="sm" variant="ghost"
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

</div>
