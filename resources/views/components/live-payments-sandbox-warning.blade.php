@props(['version'])

@if ($version->livePaymentsWhileSandbox())
    <flux:callout variant="warning" icon="exclamation-triangle" class="mb-4">
        <flux:callout.heading>Live payments</flux:callout.heading>
        <flux:callout.text>
            This version is in Sandbox status, but electronic payments are live. Any Pay Now made here charges a real card and deposits to the event's production account.
        </flux:callout.text>
    </flux:callout>
@endif
