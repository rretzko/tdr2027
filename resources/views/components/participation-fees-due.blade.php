@props(['teacher'])

@php $due = app(\App\Services\FeeReconciliationService::class)->participationDueForTeacher($teacher); @endphp

@if ($due->isNotEmpty())
    <div {{ $attributes }}>
        <flux:heading size="lg" class="mb-3">Participation fees due</flux:heading>
        <div class="space-y-3">
            @foreach ($due as $item)
                @php
                    $version = $item['version'];
                    $teacherReady = $version->epaymentTeacherReady();
                    $payable = $teacherReady && $version->participationFeePayableOnline();
                    $window = $version->participationFeeDate();
                @endphp
                <flux:card size="sm" class="border-l-4 border-l-amber-500">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <flux:heading size="base">{{ $version->name }}</flux:heading>
                            <flux:text size="sm" class="text-zinc-500">
                                {{ $item['count'] }} {{ \Illuminate\Support\Str::plural('student', $item['count']) }} unpaid, ${{ number_format($item['balanceCents'] / 100, 2) }} due
                                @if ($payable && $window?->end_at)
                                    &middot; pay online by {{ $window->end_at->forDisplay()->format('M j, g:i A') }}
                                @elseif ($teacherReady && ! $payable && $window?->start_at?->isFuture())
                                    &middot; online payment opens {{ $window->start_at->forDisplay()->format('M j, g:i A') }}
                                @endif
                            </flux:text>
                        </div>
                        <flux:button size="sm" variant="primary" icon="credit-card" :href="route('registrations.participation-fees', $version)" wire:navigate>
                            {{ $payable ? 'Pay fees' : 'View fees' }}
                        </flux:button>
                    </div>
                </flux:card>
            @endforeach
        </div>
    </div>
@endif
