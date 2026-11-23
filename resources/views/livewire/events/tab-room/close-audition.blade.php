<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.tab-room.index', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Tab Room</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Close Audition</span>
    </div>

    <div class="mb-6">
        <flux:heading size="xl">Close Audition</flux:heading>
        <flux:text size="sm" class="text-zinc-500">{{ $version->name }}</flux:text>
    </div>

    <flux:card>
        @php $isClosed = $version->getRawOriginal('status') === 'closed'; @endphp

        @if ($isClosed)
            <div class="text-center py-6">
                <flux:button variant="ghost" wire:click="reopen" wire:confirm="Reopen {{ $version->name }}? Teachers will lose the ability to see their audition results until you close again.">
                    Re-open Event
                </flux:button>

                <flux:text size="sm" class="text-zinc-500 mt-3 block">
                    @if ($version->results_released_at)
                        Auditions were closed on: {{ $version->results_released_at->format('M j, Y \a\t g:ia') }}
                    @else
                        Auditions are closed.
                    @endif
                </flux:text>
            </div>
        @else
            <div class="max-w-md mx-auto py-6 text-center space-y-4">
                <flux:text class="block">
                    Closing the audition marks {{ $version->name }} as closed and makes results available to participating teachers.
                </flux:text>

                <label class="flex items-center justify-center gap-2 text-sm cursor-pointer">
                    <flux:checkbox wire:model.live="emailTeachers" />
                    Email {{ $notifiableTeacherCount }} participating {{ Str::plural('teacher', $notifiableTeacherCount) }} that results are available
                </label>

                <flux:text size="sm" class="block text-zinc-500">
                    Closing also schedules TheDirectorsRoom.com's platform-usage invoice to this Version's Event Managers. It is emailed 24 hours later, and reopening before then cancels it.
                </flux:text>

                <flux:button variant="danger" wire:click="close" wire:confirm="Close {{ $version->name }}? This releases audition results to participating teachers{{ $emailTeachers ? ' and emails them now' : '' }}.">
                    Close Audition
                </flux:button>

                @if ($closeProblems !== [])
                    <flux:callout variant="danger" icon="exclamation-triangle" class="text-left">
                        <flux:callout.heading>Audition not closed — some candidates don't have a result yet</flux:callout.heading>
                        <flux:callout.text>
                            <ul class="list-disc ps-5 space-y-0.5">
                                @foreach ($closeProblems as $problem)
                                    <li>{{ $problem }}</li>
                                @endforeach
                            </ul>
                            <p class="mt-2">
                                @if ($needsFinalize)
                                    On <a href="{{ route('events.versions.tab-room.cutoffs', $version) }}" wire:navigate class="underline font-medium">Ensemble Cut-offs</a>, click <strong>Finalize — reject remaining</strong>, then close again.
                                @else
                                    On <a href="{{ route('events.versions.tab-room.cutoffs', $version) }}" wire:navigate class="underline font-medium">Ensemble Cut-offs</a>, apply a cutoff in each voice part listed, then close again.
                                @endif
                            </p>
                        </flux:callout.text>
                    </flux:callout>
                @endif
            </div>
        @endif
    </flux:card>
</div>
