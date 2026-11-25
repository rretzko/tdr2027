@if ($row['pendingCents'] > 0)
    <flux:badge size="sm" color="sky" icon="clock">Being confirmed</flux:badge>
@elseif ($row['balanceCents'] > 0)
    <flux:badge size="sm" color="red">${{ number_format($row['balanceCents'] / 100, 2) }} due</flux:badge>
@elseif ($row['balanceCents'] < 0)
    <flux:badge size="sm" color="blue">${{ number_format(abs($row['balanceCents']) / 100, 2) }} overpaid</flux:badge>
@elseif ($row['waivedCents'] > 0)
    <flux:badge size="sm" color="amber">Waived</flux:badge>
@else
    <flux:badge size="sm" color="green">Paid in full</flux:badge>
@endif
