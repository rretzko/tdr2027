@php use App\Support\Billing\PlatformInvoiceData; @endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
    <style>
        body { font-size: 12px; }
        .layout { width: 100%; border: none; margin-bottom: 28px; }
        .layout td { border: none; padding: 0; }
        .company-name { font-size: 18px; font-weight: bold; margin: 0 0 4px; }
        .muted { color: #555; }
        .invoice-title { font-size: 26px; font-weight: bold; letter-spacing: 2px; text-align: right; margin: 0 0 8px; }
        .meta { width: auto; margin: 0 0 0 auto; border: none; }
        .meta td { border: none; padding: 2px 0 2px 16px; text-align: right; }
        .meta td.label { color: #555; }
        .bill-to-label { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #555; margin: 0 0 4px; }
        .lines th, .lines td { padding: 8px 10px; }
        .lines td.num, .lines th.num { text-align: right; }
        .lines tr.total td { font-weight: bold; font-size: 14px; background-color: #f2f2f2; }
        .remit { border: 1px solid #333; padding: 12px 16px; width: 300px; line-height: 1.5; }
        .remit .label { font-weight: bold; margin-bottom: 4px; }
        .test-banner { border: 2px solid #b91c1c; color: #b91c1c; font-weight: bold; text-align: center; padding: 8px; font-size: 14px; margin-bottom: 20px; letter-spacing: 1px; }
        .footer { margin-top: 32px; font-size: 10px; color: #555; }
        .tagline { margin-top: 28px; font-size: 13px; font-weight: bold; }
    </style>
</head>
<body>
    @if ($data->isTest)
        <div class="test-banner">TEST: NOT A BILL</div>
    @endif

    <table class="layout">
        <tr>
            <td style="width: 55%;">
                <p class="company-name">{{ $data->company['name'] }}</p>
                @foreach ($data->company['address_lines'] as $line)
                    <div class="muted">{{ $line }}</div>
                @endforeach
            </td>
            <td style="width: 45%;">
                <p class="invoice-title">INVOICE</p>
                <table class="meta">
                    <tr><td class="label">Invoice #</td><td>{{ $data->number }}</td></tr>
                    <tr><td class="label">Issued</td><td>{{ $data->issuedAt->forDisplay()->format('F j, Y') }}</td></tr>
                    <tr><td class="label">Terms</td><td>Net {{ PlatformInvoiceData::termsDays() }}</td></tr>
                    <tr><td class="label">Due</td><td><strong>{{ $data->dueAt->forDisplay()->format('F j, Y') }}</strong></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div style="margin-bottom: 24px;">
        <p class="bill-to-label">Bill to</p>
        <div><strong>{{ $data->organizationName }}</strong></div>
        @if ($data->eventName !== '' && $data->eventName !== $data->organizationName)
            <div>{{ $data->eventName }}</div>
        @endif
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Qty</th>
                <th class="num">Rate</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    TheDirectorsRoom.com platform usage: {{ $data->versionName }}
                    @if ($data->storageIncluded)
                        <br><span class="muted">Virtual audition file storage included</span>
                    @endif
                </td>
                <td class="num">{{ number_format($data->registrantCount) }} {{ Str::plural('registrant', $data->registrantCount) }}</td>
                <td class="num">{{ PlatformInvoiceData::money($data->rateCents) }}</td>
                <td class="num">{{ PlatformInvoiceData::money($data->registrantAmountCents) }}</td>
            </tr>
            @if ($data->storage)
                <tr>
                    <td>
                        Virtual audition file storage<br>
                        <span class="muted">Candidate Access Date ({{ $data->storage->accessAt->forDisplay()->format('m/d/Y') }}) - Event Close Date ({{ $data->storage->closeAt->forDisplay()->format('m/d/Y') }}) + 1 month pre and post for testing and post-event review</span>
                    </td>
                    <td class="num">{{ $data->storage->months }} {{ Str::plural('month', $data->storage->months) }}</td>
                    <td class="num">{{ PlatformInvoiceData::money($data->storage->rateCents) }}/mo</td>
                    <td class="num">{{ PlatformInvoiceData::money($data->storage->amountCents()) }}</td>
                </tr>
            @endif
            <tr class="total">
                <td colspan="3" class="num">Total due</td>
                <td class="num">{{ PlatformInvoiceData::money($data->amountCents) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="remit">
        <div class="label">Remit payment to</div>
        <div>Make checks payable to <strong>{{ $data->company['payable_to'] }}</strong></div>
        <div>{{ $data->company['name'] }}</div>
        @foreach ($data->company['address_lines'] as $line)
            <div>{{ $line }}</div>
        @endforeach
    </div>

    <p class="tagline">It has been a pleasure working with you!</p>

    <p class="footer">Please include invoice number {{ $data->number }} with your payment. Thank you for your business.</p>
</body>
</html>
