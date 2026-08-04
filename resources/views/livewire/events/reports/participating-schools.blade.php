<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.reports', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Reports</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Participating Schools</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">Participating Schools</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — packet receipt and payment status</flux:text>
        </div>

        <div class="flex gap-2">
            <flux:button size="sm" variant="outline" wire:click="sendConfirmations" wire:confirm="Send packet-received confirmation emails to every teacher with an outstanding confirmation?">
                Send Confirmations
            </flux:button>
            <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route('events.versions.reports.participating-schools.pdf', ['version' => $version, 'search' => $search, 'packetFilter' => $packetFilter, 'balanceFilter' => $balanceFilter])" target="_blank">
                Export PDF
            </flux:button>
        </div>
    </div>

    <div class="flex flex-col sm:flex-row gap-3 mb-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by first name, last name, or school..." icon="magnifying-glass" class="sm:max-w-sm" />
        <flux:select wire:model.live="packetFilter" placeholder="All packets" class="sm:max-w-2xs">
            <flux:select.option value="">All packets</flux:select.option>
            <flux:select.option value="outstanding">Packets outstanding</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="balanceFilter" placeholder="All balances" class="sm:max-w-2xs">
            <flux:select.option value="">All balances</flux:select.option>
            <flux:select.option value="due">Balance due</flux:select.option>
            <flux:select.option value="overpayment">Overpayment</flux:select.option>
            <flux:select.option value="none">No balance due</flux:select.option>
        </flux:select>
    </div>

    @if ($rows->isEmpty())
        <flux:callout variant="info" icon="magnifying-glass">
            <flux:callout.text>
                @if ($search !== '' || $packetFilter !== '' || $balanceFilter !== '')
                    No schools match your search or filter.
                @else
                    No schools have registered candidates yet.
                @endif
            </flux:callout.text>
        </flux:callout>
    @else
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

        {{-- Cards below lg:, table at lg:+ --}}
        <div class="lg:hidden space-y-3">
            @foreach ($rows as $row)
                @php $badge = $balanceBadge($row['balanceCents']); @endphp
                <flux:card size="sm">
                    <div class="flex items-start justify-between gap-3 mb-2">
                        <div>
                            <flux:heading size="base">{{ $row['school']->name ?? '—' }}</flux:heading>
                            <flux:text size="sm" class="text-zinc-500">{{ $row['teacher']->user->name }}</flux:text>
                        </div>
                        <flux:badge color="{{ $badge['color'] }}" size="sm">{{ $badge['label'] }}</flux:badge>
                    </div>
                    <div class="space-y-1 text-sm mb-3">
                        <div>{{ $row['teacher']->user->email }}</div>
                        <div class="text-zinc-500">{{ $row['phones'] }}</div>
                        <div class="text-zinc-500">{{ $row['count'] }} registered — due ${{ number_format($row['dueCents'] / 100, 2) }}, paid ${{ number_format($row['paidCents'] / 100, 2) }}</div>
                    </div>
                    <div class="flex items-center gap-3">
                        <flux:checkbox
                            wire:click="togglePacket({{ $row['school']->id }}, {{ $row['teacher']->id }})"
                            :checked="$row['packet']?->isReceived() ?? false"
                            label="Packet received"
                        />
                        <flux:spacer />
                        <flux:modal.trigger name="payment-form">
                            <flux:button size="sm" variant="outline" wire:click="openPayment({{ $row['school']->id }}, {{ $row['teacher']->id }})">
                                Payment
                            </flux:button>
                        </flux:modal.trigger>
                    </div>
                </flux:card>
            @endforeach
        </div>

        <div class="hidden lg:block">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortColumn === 'school'" :direction="$sortDirection" wire:click="sortBy('school')">School</flux:table.column>
                    <flux:table.column sortable :sorted="$sortColumn === 'teacher'" :direction="$sortDirection" wire:click="sortBy('teacher')">Teacher</flux:table.column>
                    <flux:table.column>Contact</flux:table.column>
                    <flux:table.column>Packet</flux:table.column>
                    <flux:table.column>Registered</flux:table.column>
                    <flux:table.column>Due</flux:table.column>
                    <flux:table.column>Paid</flux:table.column>
                    <flux:table.column>Balance</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($rows as $row)
                        @php $badge = $balanceBadge($row['balanceCents']); @endphp
                        <flux:table.row :key="$row['key']">
                            <flux:table.cell class="font-medium">{{ $row['school']->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $row['teacher']->user->name }}</flux:table.cell>
                            <flux:table.cell>
                                <div>{{ $row['teacher']->user->email }}</div>
                                <div class="text-zinc-500">{{ $row['phones'] }}</div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:checkbox
                                    wire:click="togglePacket({{ $row['school']->id }}, {{ $row['teacher']->id }})"
                                    :checked="$row['packet']?->isReceived() ?? false"
                                />
                            </flux:table.cell>
                            <flux:table.cell>{{ $row['count'] }}</flux:table.cell>
                            <flux:table.cell>${{ number_format($row['dueCents'] / 100, 2) }}</flux:table.cell>
                            <flux:table.cell>${{ number_format($row['paidCents'] / 100, 2) }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge color="{{ $badge['color'] }}" size="sm">{{ $badge['label'] }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:modal.trigger name="payment-form">
                                    <flux:button size="sm" variant="outline" wire:click="openPayment({{ $row['school']->id }}, {{ $row['teacher']->id }})">
                                        Payment
                                    </flux:button>
                                </flux:modal.trigger>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <flux:modal name="payment-form" class="md:w-96">
        <form wire:submit="savePayment" class="space-y-6">
            <div>
                <flux:heading size="lg">Record payment</flux:heading>
            </div>

            <flux:select wire:model="paymentType" label="Payment type" placeholder="Select a payment type...">
                <flux:select.option value="check">Check</flux:select.option>
                <flux:select.option value="purchase_order">Purchase Order</flux:select.option>
                <flux:select.option value="cash">Cash</flux:select.option>
                <flux:select.option value="other">Other</flux:select.option>
            </flux:select>

            <flux:input wire:model="amount" type="number" step="0.01" min="0" label="Amount paid" placeholder="0.00" />

            <flux:input wire:model="referenceNumber" label="Check or transaction number" />

            <flux:textarea wire:model="comments" label="Comments" rows="2" />

            @if ($errors->any())
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>Please correct the errors above before saving.</flux:callout.text>
                </flux:callout>
            @endif

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
