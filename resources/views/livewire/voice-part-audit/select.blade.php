{{--
    Keyed on the stored voice part + $refreshNonce so a refused change (which
    leaves the server HTML identical) still re-renders the select back to
    the stored value instead of leaving the rejected choice showing.
--}}
<div wire:key="vp-{{ $layout }}-{{ $candidate->id }}-{{ $candidate->voice_part_id }}-{{ $refreshNonce }}" class="space-y-1">
    <flux:select
        size="sm"
        placeholder="Select a voice part..."
        x-on:change="$wire.changeVoicePart({{ $candidate->id }}, $event.target.value)"
    >
        @foreach ($row->offered as $voicePart)
            <flux:select.option value="{{ $voicePart->id }}" :selected="$voicePart->id === $candidate->voice_part_id">{{ $voicePart->name }}</flux:select.option>
        @endforeach
    </flux:select>
    @unless ($row->isOffered)
        <flux:badge color="red" size="sm">
            {{ $candidate->voicePart->name ?? 'None' }} — not offered for {{ $row->grade !== null ? 'grade '.$row->grade : 'this student' }}
        </flux:badge>
    @endunless
</div>
