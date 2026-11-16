{{-- Row actions stay visible in every status; the component explains in a toast when one doesn't apply. --}}
@if ($status === \App\Enums\PlatformInvoiceStatus::Paid)
    <flux:button size="sm" variant="ghost" wire:click="markUnpaid({{ $invoice->id }})" wire:confirm="Mark invoice {{ $invoice->number }} unpaid?">Mark unpaid</flux:button>
@else
    <flux:button size="sm" icon="check" wire:click="openMarkPaid({{ $invoice->id }})">Mark paid</flux:button>
@endif
<flux:button size="sm" icon="paper-airplane" wire:click="openResend({{ $invoice->id }})">Resend</flux:button>
@if (in_array($status, \App\Enums\PlatformInvoiceStatus::issuedStates(), true))
    <flux:button size="sm" variant="ghost" icon="document-arrow-down" :href="route('founder.invoices.pdf', $invoice)" target="_blank">PDF</flux:button>
@endif
