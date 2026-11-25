@foreach ($row['payments'] as $allocation)
    @php $payment = $allocation->paymentTransaction; @endphp
    <div class="text-xs text-zinc-500 dark:text-zinc-400">
        {{ \App\Services\FeeReconciliationService::methodLabel($payment) }}
        {{ $allocation->amount < 0 ? '-' : '' }}${{ number_format(abs($allocation->amount) / 100, 2) }},
        {{ ($payment->paid_at ?? $payment->created_at)->format('M j, Y') }}{{ $payment->reference_number ? ' (Ref: '.$payment->reference_number.')' : '' }}
    </div>
@endforeach
