<div class="space-y-2">
    @foreach ($candidate->uploadFiles->sortBy(fn ($file) => $file->versionUploadFile->order_by ?? 0) as $file)
        @php
            try {
                $src = \Illuminate\Support\Facades\Storage::disk('s3')->temporaryUrl($file->url, now()->addHour());
            } catch (\Throwable) {
                $src = null;
            }
        @endphp
        <div wire:key="rec-{{ $layout }}-{{ $file->id }}">
            <div class="text-xs">
                <span class="font-medium">{{ $file->versionUploadFile->name ?? 'Recording' }}</span>
                @if ($file->original_filename)
                    <span class="text-zinc-500 dark:text-zinc-400">· {{ $file->original_filename }}</span>
                @endif
            </div>
            @if ($src)
                {{-- preload="none": nothing downloads until play is pressed --}}
                <audio controls preload="none" class="h-8 w-full min-w-56">
                    <source src="{{ $src }}">
                </audio>
            @else
                <flux:text size="sm" class="text-zinc-500">Recording unavailable.</flux:text>
            @endif
        </div>
    @endforeach
</div>
