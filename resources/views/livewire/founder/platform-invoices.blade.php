@php use App\Support\Billing\PlatformInvoiceData; @endphp
<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">Platform Invoices</flux:heading>
            <flux:subheading>Invoices are emailed to a Version's Event Managers 24 hours after its audition is closed.</flux:subheading>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button icon="currency-dollar" wire:click="openRates">Rates</flux:button>
            <flux:button icon="paper-airplane" variant="primary" wire:click="openTest">Send test invoice</flux:button>
        </div>
    </div>

    {{-- Totals --}}
    <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text size="sm" class="text-zinc-500">Outstanding</flux:text>
            <flux:heading size="lg">{{ PlatformInvoiceData::money($totals['outstanding']) }}</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text size="sm" class="text-zinc-500">Overdue</flux:text>
            <flux:heading size="lg" class="{{ $totals['overdue'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ PlatformInvoiceData::money($totals['overdue']) }}</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text size="sm" class="text-zinc-500">Paid this year</flux:text>
            <flux:heading size="lg">{{ PlatformInvoiceData::money($totals['paidThisYear']) }}</flux:heading>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-4">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            placeholder="Search number, event or version..."
            class="max-w-sm"
            clearable
        />
        <flux:select wire:model.live="statusFilter" class="max-w-48">
            <flux:select.option value="">All statuses</flux:select.option>
            @foreach ($statuses as $status)
                <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
            @endforeach
            <flux:select.option value="overdue">Overdue</flux:select.option>
            <flux:select.option value="attention">Needs attention</flux:select.option>
        </flux:select>
    </div>

    {{-- Mobile: card list --}}
    <div class="flex flex-col gap-3 md:hidden">
        @forelse ($invoices as $invoice)
            @php $status = $invoice->statusEnum(); @endphp
            <div wire:key="card-{{ $invoice->id }}" class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-2 flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <flux:text class="font-medium">{{ $invoice->number ?? 'Not yet numbered' }}</flux:text>
                        <flux:text size="sm" class="truncate text-zinc-500">{{ $invoice->version?->event?->name }}: {{ $invoice->version?->name }}</flux:text>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <flux:badge :color="$status->color()" size="sm">{{ $status->label() }}</flux:badge>
                        @if ($invoice->isOverdue())
                            <flux:badge color="red" size="sm">Overdue</flux:badge>
                        @endif
                        @if ($invoice->needs_attention)
                            <flux:badge color="red" size="sm">Needs attention</flux:badge>
                            @if ($invoice->attention_reason)
                                <flux:text size="sm" class="text-red-600 dark:text-red-400">{{ $invoice->attention_reason }}</flux:text>
                            @endif
                        @endif
                    </div>
                </div>
                <dl class="mb-3 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                    <dt class="text-zinc-500">Amount</dt>
                    <dd>{{ $invoice->amount_cents === null ? '—' : PlatformInvoiceData::money($invoice->amount_cents).' ('.number_format((int) $invoice->registrant_count).')' }}</dd>
                    @if ($invoice->storage_included || $invoice->storage_months)
                        <dt class="text-zinc-500">Storage</dt>
                        <dd>{{ $invoice->storage_included ? 'Storage included' : 'Incl. '.$invoice->storage_months.' mo storage' }}</dd>
                    @endif
                    <dt class="text-zinc-500">{{ $status === \App\Enums\PlatformInvoiceStatus::Pending ? 'Sends' : 'Sent' }}</dt>
                    <dd>{{ ($status === \App\Enums\PlatformInvoiceStatus::Pending ? $invoice->send_at : $invoice->sent_at)?->forDisplay()->format('M j, Y g:i A') ?? '—' }}</dd>
                    <dt class="text-zinc-500">Due</dt>
                    <dd>{{ $invoice->due_at?->forDisplay()->format('M j, Y') ?? '—' }}</dd>
                    <dt class="text-zinc-500">Paid</dt>
                    <dd>{{ $invoice->paid_at?->forDisplay()->format('M j, Y') ?? '—' }}{{ $invoice->payment_note ? ' · '.$invoice->payment_note : '' }}</dd>
                </dl>
                <div class="flex flex-wrap gap-2">
                    @include('livewire.founder.partials.platform-invoice-actions', ['invoice' => $invoice, 'status' => $status])
                </div>
            </div>
        @empty
            <flux:text class="text-zinc-500">
                {{ filled($search) || $statusFilter !== '' ? 'No matching invoices.' : 'No invoices yet. One is scheduled each time an audition is closed.' }}
            </flux:text>
        @endforelse
    </div>

    {{-- Desktop: full table --}}
    <div class="hidden md:block">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Invoice</flux:table.column>
                <flux:table.column>Event / Version</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column align="end">Registrants</flux:table.column>
                <flux:table.column align="end">Amount</flux:table.column>
                <flux:table.column>Sent</flux:table.column>
                <flux:table.column>Due</flux:table.column>
                <flux:table.column>Paid</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($invoices as $invoice)
                    @php $status = $invoice->statusEnum(); @endphp
                    <flux:table.row :key="$invoice->id">
                        <flux:table.cell class="font-medium whitespace-nowrap">{{ $invoice->number ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:text class="font-medium">{{ $invoice->version?->name }}</flux:text>
                            <flux:text size="sm" class="text-zinc-500">{{ $invoice->version?->event?->name }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap items-center gap-1">
                                <flux:badge :color="$status->color()" size="sm">{{ $status->label() }}</flux:badge>
                                @if ($invoice->isOverdue())
                                    <flux:badge color="red" size="sm">Overdue</flux:badge>
                                @endif
                                @if ($invoice->needs_attention)
                                    <flux:badge color="red" size="sm">Needs attention</flux:badge>
                                @endif
                            </div>
                            @if ($invoice->needs_attention && $invoice->attention_reason)
                                <flux:text size="sm" class="text-red-600 dark:text-red-400">{{ $invoice->attention_reason }}</flux:text>
                            @endif
                            @if ($invoice->storage_included)
                                <flux:text size="sm" class="text-zinc-500">Storage included</flux:text>
                            @elseif ($invoice->storage_months)
                                <flux:text size="sm" class="text-zinc-500">Incl. {{ $invoice->storage_months }} mo storage</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ $invoice->registrant_count === null ? '—' : number_format($invoice->registrant_count) }}</flux:table.cell>
                        <flux:table.cell align="end" class="whitespace-nowrap">{{ $invoice->amount_cents === null ? '—' : PlatformInvoiceData::money($invoice->amount_cents) }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">
                            @if ($status === \App\Enums\PlatformInvoiceStatus::Pending && $invoice->send_at)
                                <flux:text size="sm" class="text-zinc-500">Sends {{ $invoice->send_at->forDisplay()->format('M j, g:i A') }}</flux:text>
                            @else
                                {{ $invoice->sent_at?->forDisplay()->format('M j, Y') ?? '—' }}
                                @if ($invoice->resend_count > 0)
                                    <flux:text size="sm" class="text-zinc-500">Resent {{ $invoice->resend_count }}×</flux:text>
                                @endif
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $invoice->due_at?->forDisplay()->format('M j, Y') ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">
                            {{ $invoice->paid_at?->forDisplay()->format('M j, Y') ?? '—' }}
                            @if ($invoice->payment_note)
                                <flux:text size="sm" class="text-zinc-500">{{ $invoice->payment_note }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center justify-end gap-2">
                                @include('livewire.founder.partials.platform-invoice-actions', ['invoice' => $invoice, 'status' => $status])
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="9" class="text-zinc-500">
                            {{ filled($search) || $statusFilter !== '' ? 'No matching invoices.' : 'No invoices yet. One is scheduled each time an audition is closed.' }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    {{-- Mark paid --}}
    <flux:modal name="mark-paid" class="w-full max-w-md">
        <form wire:submit="markPaid" class="space-y-4">
            <flux:heading>Mark invoice paid</flux:heading>
            <flux:input type="date" wire:model="paidOn" label="Paid on" />
            <flux:input wire:model="paymentNote" label="Note (optional)" placeholder="e.g. Check #1234" />
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Mark paid</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Resend --}}
    <flux:modal name="resend-invoice" class="w-full max-w-md">
        <div class="space-y-4">
            <flux:heading>{{ $resending?->statusEnum() === \App\Enums\PlatformInvoiceStatus::Pending ? 'Send' : 'Resend' }} invoice {{ $resending?->number }}</flux:heading>
            <flux:text>
                @if ($resending?->statusEnum() === \App\Enums\PlatformInvoiceStatus::Pending)
                    This invoice couldn't be sent ({{ $resending?->attention_reason ?? 'needs attention' }}). Every check runs again now; if they pass, it is numbered and sent using current numbers.
                @else
                    The stored invoice is re-sent unchanged (same number, amount and due date), with a BCC to you.
                @endif
            </flux:text>
            @if ($resendRecipients === [] && $resending?->statusEnum() !== \App\Enums\PlatformInvoiceStatus::Pending)
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>{{ $resending?->version?->name }} currently has no Event Managers. Assign one first.</flux:callout.text>
                </flux:callout>
            @elseif ($resendRecipients !== [])
                <div>
                    <flux:text size="sm" class="mb-1 text-zinc-500">Recipients</flux:text>
                    <ul class="list-inside list-disc text-sm">
                        @foreach ($resendRecipients as $email)
                            <li>{{ $email }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" icon="paper-airplane" wire:click="resend">Send</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Test send --}}
    <flux:modal name="test-invoice" class="w-full max-w-md">
        <form wire:submit="sendTest" class="space-y-4">
            <flux:heading>Send test invoice</flux:heading>
            <flux:text>Builds an invoice from the Version's current registrant count and rate, marked "TEST: NOT A BILL", and emails it only to {{ config('billing.founder_email') }}. Nothing is saved.</flux:text>
            <flux:select wire:model="testVersionId" label="Version" variant="listbox" searchable placeholder="Choose a version...">
                @foreach ($testVersions as $version)
                    <flux:select.option value="{{ $version->id }}">{{ $version->event?->name }}: {{ $version->name }} ({{ $version->status->label() }})</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" icon="paper-airplane">Send test</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Per-Event rates --}}
    <flux:modal name="event-rates" class="w-full max-w-lg">
        <form wire:submit="saveRates" class="space-y-4">
            <flux:heading>Rates and storage</flux:heading>
            <flux:text>Leave a rate blank to use the default of {{ $defaultRate }}. Check "Storage included" when the rate is all-in: that Event's virtual auditions get no file-storage line, and a rate must be entered. Changes apply to invoices that haven't been sent yet; sent invoices keep their terms.</flux:text>
            <div class="max-h-96 space-y-3 overflow-y-auto pr-1">
                @foreach ($rateEvents as $event)
                    <div wire:key="rate-{{ $event->id }}" class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                        <flux:text class="min-w-0 truncate">{{ $event->name }}</flux:text>
                        <div class="flex shrink-0 items-center gap-3">
                            <div class="w-32">
                                <flux:input wire:model="rates.{{ $event->id }}" size="sm" icon="currency-dollar" placeholder="{{ ltrim($defaultRate, '$') }}" />
                            </div>
                            <flux:checkbox wire:model="storageIncluded.{{ $event->id }}" label="Storage included" />
                        </div>
                    </div>
                @endforeach
            </div>
            @if ($errors->any())
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>{{ $errors->first() }}</flux:callout.text>
                </flux:callout>
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
