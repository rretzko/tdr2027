{{-- $before + voice part + $after form the question; $yes, $changeAction, $offered --}}
<flux:modal name="confirm-voice-part" class="w-full max-w-md">
    <div class="space-y-6">
        <flux:heading size="lg">Confirm Voice Part</flux:heading>
        @if ($offered)
            <flux:text>{{ $before }} <strong>{{ $candidate->voicePart->name }}</strong>{{ $after }}</flux:text>
        @else
            <flux:text><strong>{{ $candidate->voicePart->name ?? 'This voice part' }}</strong> isn't offered for this grade. Please choose a voice part.</flux:text>
        @endif
        <div class="flex flex-wrap justify-end gap-2">
            <flux:button variant="ghost" wire:click="{{ $changeAction }}">Change voice part</flux:button>
            @if ($offered)
                <flux:button variant="primary" wire:click="confirmVoicePart({{ $candidate->voice_part_id }})">{{ $yes }}</flux:button>
            @endif
        </div>
    </div>
</flux:modal>
