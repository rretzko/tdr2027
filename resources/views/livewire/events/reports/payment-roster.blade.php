<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.reports', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Reports</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Payment Roster</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">Payment Roster</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — every payment recorded for this Version</flux:text>
        </div>

        <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route('events.versions.reports.payment-roster.pdf', ['version' => $version, 'search' => $search, 'schoolFilter' => $schoolFilter, 'paymentTypeFilter' => $paymentTypeFilter])" target="_blank">
            Export PDF
        </flux:button>
    </div>

    <div class="flex flex-col sm:flex-row gap-3 mb-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by school, teacher, or candidate..." icon="magnifying-glass" class="sm:max-w-sm" />
        <flux:select wire:model.live="schoolFilter" placeholder="All schools" class="sm:max-w-2xs">
            <flux:select.option value="">All schools</flux:select.option>
            @foreach ($schoolOptions as $school)
                <flux:select.option value="{{ $school }}">{{ $school }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="paymentTypeFilter" placeholder="All payment types" class="sm:max-w-2xs">
            <flux:select.option value="">All payment types</flux:select.option>
            @foreach (\App\Enums\PaymentType::cases() as $type)
                <flux:select.option value="{{ $type->value }}">{{ $type->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($rows->isEmpty())
        <flux:callout variant="info" icon="magnifying-glass">
            <flux:callout.text>
                @if ($search !== '' || $schoolFilter !== '' || $paymentTypeFilter !== '')
                    No payments match your search or filter.
                @else
                    No payments have been recorded for this Version yet.
                @endif
            </flux:callout.text>
        </flux:callout>
    @else
        {{-- Cards below lg:, table at lg:+ --}}
        <div class="lg:hidden space-y-3">
            @foreach ($rows as $row)
                <flux:card size="sm">
                    <div class="flex items-start justify-between gap-3 mb-2">
                        <div>
                            <flux:heading size="base">{{ $row['schoolName'] ?? '—' }}</flux:heading>
                            <flux:text size="sm" class="text-zinc-500">{{ $row['teacherName'] }}{{ $row['candidateName'] ? ' — '.$row['candidateName'] : '' }}</flux:text>
                        </div>
                        <flux:badge color="zinc" size="sm">{{ $row['paymentType']->label() }}</flux:badge>
                    </div>
                    <div class="space-y-1 text-sm">
                        <div>${{ number_format($row['amountCents'] / 100, 2) }}</div>
                        @if ($row['referenceNumber'])
                            <div class="text-zinc-500">Ref: {{ $row['referenceNumber'] }}</div>
                        @endif
                        @if ($row['comments'])
                            <div class="text-zinc-500">{{ $row['comments'] }}</div>
                        @endif
                    </div>
                </flux:card>
            @endforeach
        </div>

        <div class="hidden lg:block">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortColumn === 'school'" :direction="$sortDirection" wire:click="sortBy('school')">School</flux:table.column>
                    <flux:table.column sortable :sorted="$sortColumn === 'teacher'" :direction="$sortDirection" wire:click="sortBy('teacher')">Teacher</flux:table.column>
                    <flux:table.column sortable :sorted="$sortColumn === 'candidate'" :direction="$sortDirection" wire:click="sortBy('candidate')">Candidate</flux:table.column>
                    <flux:table.column>Type</flux:table.column>
                    <flux:table.column>Amount</flux:table.column>
                    <flux:table.column>Reference</flux:table.column>
                    <flux:table.column>Comments</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($rows as $row)
                        <flux:table.row :key="$row['source']">
                            <flux:table.cell class="font-medium">{{ $row['schoolName'] ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $row['teacherName'] }}</flux:table.cell>
                            <flux:table.cell>{{ $row['candidateName'] ?? '—' }}</flux:table.cell>
                            <flux:table.cell><flux:badge color="zinc" size="sm">{{ $row['paymentType']->label() }}</flux:badge></flux:table.cell>
                            <flux:table.cell>${{ number_format($row['amountCents'] / 100, 2) }}</flux:table.cell>
                            <flux:table.cell>{{ $row['referenceNumber'] ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="max-w-[220px] whitespace-normal break-words text-zinc-500">{{ $row['comments'] ?? '—' }}</div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</div>
