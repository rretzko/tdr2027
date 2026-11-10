{{--
    Event Manager "Waive fees" modal (docs/plans/student-fee-waivers.md).
    Backed by App\Concerns\ManagesFeeWaivers; include it only when
    $canWaiveFees, passing $waivableFeeTypes from waivableFeeTypes().
--}}
<flux:modal name="candidate-fee-waivers" class="md:w-[32rem]">
    <form wire:submit="saveWaivers" class="space-y-6">
        <div>
            <flux:heading size="lg">Waive fees</flux:heading>
            <flux:text class="mt-1">{{ $waivingCandidateName }}</flux:text>
        </div>

        @if ($waivableFeeTypes === [])
            <flux:callout variant="info" icon="information-circle">
                <flux:callout.text>This Version doesn't charge any fees, so there's nothing to waive.</flux:callout.text>
            </flux:callout>
        @else
            <flux:checkbox.group wire:model="waive_fee_types" label="Fees to waive" description="A waived fee is $0 for this student. Uncheck a fee to charge it again.">
                @foreach ($waivableFeeTypes as $entry)
                    <flux:checkbox value="{{ $entry['type']->value }}" label="{{ $entry['type']->label() }} (${{ number_format($entry['cents'] / 100, 2) }})" />
                @endforeach
            </flux:checkbox.group>

            <flux:textarea wire:model="waive_reason" label="Reason" description="Required when waiving a fee. Visible to Event and Registration Managers only." rows="2" />

            @if ($waivingPaidCents > 0)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.text>
                        ${{ number_format($waivingPaidCents / 100, 2) }} has already been paid for this student. Waiving a paid fee leaves a credit, which you'll need to refund or reallocate.
                    </flux:callout.text>
                </flux:callout>
            @endif
        @endif

        @if ($errors->hasAny(['waive_fee_types', 'waive_fee_types.*', 'waive_reason']))
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:callout.text>Please correct the errors above before saving.</flux:callout.text>
            </flux:callout>
        @endif

        <div class="flex gap-2">
            <flux:spacer />
            <flux:modal.close>
                <flux:button variant="ghost">Cancel</flux:button>
            </flux:modal.close>
            @if ($waivableFeeTypes !== [])
                <flux:button type="submit" variant="primary">Save</flux:button>
            @endif
        </div>
    </form>
</flux:modal>
