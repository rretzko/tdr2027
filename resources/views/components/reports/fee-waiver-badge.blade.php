{{--
    "Fee waived" badge for a Candidate with any CandidateFeeWaiver rows
    (docs/plans/student-fee-waivers.md). Expects feeWaivers eager-loaded.
    The reason is for Event/Registration Managers only — pass
    :show-reason="false" on any teacher- or student-facing view.
--}}
@props(['candidate', 'showReason' => true])

@if ($candidate->feeWaivers->isNotEmpty())
    @php
        $waivedLabels = $candidate->feeWaivers
            ->map(fn ($waiver) => \App\Enums\FeeType::from($waiver->getRawOriginal('fee_type'))->label())
            ->sort()
            ->implode(', ');
        $tooltip = 'Waived: '.$waivedLabels;
        if ($showReason && filled($candidate->feeWaivers->first()->reason)) {
            $tooltip .= ' — '.$candidate->feeWaivers->first()->reason;
        }
    @endphp
    <flux:tooltip :content="$tooltip">
        <flux:badge color="amber" size="sm" icon="receipt-percent">Fee waived</flux:badge>
    </flux:tooltip>
@endif
