<div>
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('registrations.index') }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Registrations</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('registrations.version', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Estimate Form</span>
    </div>

    <flux:heading size="xl" class="mb-6">Estimate Form</flux:heading>

    @if ($schools->isEmpty())
        <flux:callout variant="info" icon="information-circle">
            <flux:callout.text>You have no candidates registered for this Version yet.</flux:callout.text>
        </flux:callout>
    @else

    @if ($membershipCardRequired)
        <flux:card class="max-w-xl mb-6">
            <flux:heading size="lg" class="mb-1">Membership Card</flux:heading>
            <flux:text size="sm" class="text-zinc-500 mb-4">This Event requires proof of membership on your Estimate Form.</flux:text>

            @if ($membershipCardImageUrl !== null)
                <img
                    src="{{ $membershipCardImageUrl }}"
                    alt="Membership card"
                    class="mb-4 max-h-48 w-auto rounded-md border border-zinc-200 object-contain dark:border-zinc-700"
                />
            @else
                <flux:callout variant="warning" icon="exclamation-triangle" class="mb-4">
                    <flux:callout.text>You don't have a membership card on file yet — it will show as a placeholder on your downloaded PDF.</flux:callout.text>
                </flux:callout>
            @endif

            <flux:button size="sm" variant="ghost" icon="pencil" :href="route('organizations.index')" wire:navigate>
                Update membership information
            </flux:button>
        </flux:card>
    @endif

    @if ($schools->count() === 1)
        @php $school = $schools->first(); @endphp
        <flux:card class="max-w-xl">
            <flux:heading size="lg">{{ $school->name }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 mb-4">{{ $registeredCounts->get($school->id, 0) }} registered candidate(s)</flux:text>

            <div class="space-y-1 mb-4">
                <div class="flex justify-between text-sm">
                    <span class="text-zinc-500">Fee subtotal</span>
                    <span>${{ number_format($singleSchoolData->feeSubtotalCents / 100, 2) }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-zinc-500">ePayments</span>
                    <span>${{ number_format($singleSchoolData->ePaymentsCents / 100, 2) }}</span>
                </div>
                <div class="flex justify-between font-medium">
                    <span>Balance due</span>
                    <span>{{ $singleSchoolData->balanceDueCents < 0 ? '-' : '' }}${{ number_format(abs($singleSchoolData->balanceDueCents) / 100, 2) }}</span>
                </div>
            </div>

            @if ($singleSchoolData->truncated)
                <flux:callout variant="warning" icon="exclamation-triangle" class="mb-4">
                    <flux:callout.text>Only the first {{ $version->max_registrants }} registered candidates (this Version's maximum) appear on the Estimate Form — contact your Event Manager if this doesn't look right.</flux:callout.text>
                </flux:callout>
            @endif

            <flux:button variant="primary" icon="arrow-down-tray" :href="route('registrations.estimate-form-pdf', [$version, $school])">
                Download PDF
            </flux:button>
        </flux:card>
    @else
        <flux:text size="sm" class="text-zinc-500 mb-4">You have registered candidates at more than one school for this Version — download a separate Estimate Form for each.</flux:text>

        <div class="space-y-3 max-w-xl">
            @foreach ($schools as $school)
                <flux:card size="sm">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <flux:heading size="base">{{ $school->name }}</flux:heading>
                            <flux:text size="sm" class="text-zinc-500">{{ $registeredCounts->get($school->id, 0) }} registered candidate(s)</flux:text>
                        </div>
                        <flux:button size="sm" icon="arrow-down-tray" :href="route('registrations.estimate-form-pdf', [$version, $school])">
                            Download PDF
                        </flux:button>
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif

    @endif
</div>
