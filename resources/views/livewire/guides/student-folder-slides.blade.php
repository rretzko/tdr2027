<div>
    <flux:modal name="student-folder-slides" scroll="body" class="md:w-[64rem]">
        @if (count($slides) > 0)
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:heading size="lg">{{ $slides[$current]['title'] }}</flux:heading>
                        <flux:subheading>Slide {{ $current + 1 }} of {{ count($slides) }}</flux:subheading>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button
                            icon="arrow-top-right-on-square"
                            variant="outline"
                            :href="$this->viewUrl()"
                            target="_blank"
                            wire:key="preview-{{ $current }}"
                        >
                            Preview
                        </flux:button>
                        <flux:button
                            icon="arrow-down-tray"
                            variant="outline"
                            :href="$this->downloadUrl()"
                            wire:key="download-{{ $current }}"
                        >
                            Download
                        </flux:button>
                    </div>
                </div>

                <div class="flex items-center justify-center rounded-lg border border-zinc-200 bg-zinc-50 p-2 dark:border-white/10 dark:bg-white/5">
                    <img
                        src="{{ $this->viewUrl() }}"
                        wire:key="slide-image-{{ $current }}"
                        alt="{{ $slides[$current]['title'] }}"
                        class="max-h-[42rem] w-auto rounded"
                    >
                </div>

                <div class="text-center text-sm text-zinc-500 dark:text-white/60">
                    {{ $slides[$current]['title'] }}
                </div>

                <div class="flex items-center justify-between">
                    <flux:button icon="chevron-left" variant="outline" wire:click="previous" :disabled="$current === 0">
                        Previous
                    </flux:button>

                    <div class="flex flex-wrap items-center justify-center gap-1">
                        @foreach ($slides as $index => $slide)
                            <button
                                type="button"
                                wire:click="goTo({{ $index }})"
                                aria-label="Go to slide {{ $index + 1 }}: {{ $slide['title'] }}"
                                class="h-2 w-2 rounded-full {{ $index === $current ? 'bg-zinc-800 dark:bg-white' : 'bg-zinc-300 dark:bg-white/20' }}"
                            ></button>
                        @endforeach
                    </div>

                    <flux:button icon="chevron-right" variant="outline" wire:click="next" :disabled="$current === count($slides) - 1">
                        Next
                    </flux:button>
                </div>
            </div>
        @else
            <flux:heading size="lg">Student Folder Slides</flux:heading>
            <flux:subheading>No slides are available right now.</flux:subheading>
        @endif

        <div class="mt-6 flex justify-end">
            <flux:modal.close>
                <flux:button variant="ghost">Close</flux:button>
            </flux:modal.close>
        </div>
    </flux:modal>
</div>
