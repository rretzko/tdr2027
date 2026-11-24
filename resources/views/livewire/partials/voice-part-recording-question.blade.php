{{--
    Asked whenever a voice part is changed for a candidate with recordings.
    $model: the deferred wire:model holding the new voice part id
    $currentId: the stored voice part id · $names: [voice part id => name]
    Driven from $wire because plain wire:model doesn't round-trip.
--}}
<div
    wire:key="recording-question-{{ $currentId }}"
    x-data="{ names: @js((object) $names), current: @js((string) $currentId) }"
    x-show="$wire.{{ $model }} && String($wire.{{ $model }}) !== current"
    x-cloak
>
    <flux:callout variant="warning" icon="musical-note">
        <flux:callout.heading>
            <span>Do the existing recordings match <span x-text="names[$wire.{{ $model }}]"></span>?</span>
        </flux:callout.heading>
        <flux:callout.text>
            <flux:radio.group wire:model="recording_decision" class="mt-2">
                <flux:radio value="match" label="Yes, the recordings are of the new voice part" description="The recordings and their approvals are kept." />
                <flux:radio value="remove" label="No, remove them" description="Every recording for this candidate is permanently deleted and must be uploaded again." />
            </flux:radio.group>
        </flux:callout.text>
    </flux:callout>
    <flux:error name="recording_decision" />
</div>
