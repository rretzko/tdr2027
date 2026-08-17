<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
    <style>
        .estimate-header { margin-bottom: 16px; }
        .estimate-header img { max-height: 50px; margin-bottom: 6px; }
        .estimate-header .version-name { font-size: 14px; font-weight: bold; margin: 0; }
        .estimate-header .version-subtitle { font-size: 12px; margin: 0 0 6px; }
        .estimate-header .meta-line { font-size: 10px; color: #555; margin: 0; }
        .estimate-header .max-line { font-size: 11px; font-weight: bold; margin-top: 6px; }

        .candidate-table th, .candidate-table td { text-align: center; }
        .candidate-table th:nth-child(1), .candidate-table td:nth-child(1) { text-align: right; }
        .candidate-table th:nth-child(2), .candidate-table td:nth-child(2),
        .candidate-table th:nth-child(3), .candidate-table td:nth-child(3) { text-align: left; }

        .truncated-note { font-size: 10px; color: #666; font-style: italic; margin-top: -12px; margin-bottom: 16px; }

        .totals-table th, .totals-table td { text-align: center; }

        .card-placeholder {
            margin-top: 20px;
            border: 1px dashed #999;
            padding: 60px 20px;
            text-align: center;
            color: #666;
            font-style: italic;
        }

        .card-image { margin-top: 20px; max-width: 100%; max-height: 350px; }

        .mailto-box { border: 1px solid #333; width: 320px; margin-top: 60px; }
        .mailto-box .mailto-label { background: #f2f2f2; font-weight: bold; text-align: center; padding: 6px; border-bottom: 1px solid #333; }
        .mailto-box .mailto-body { padding: 14px; text-align: center; line-height: 1.5; }
    </style>
</head>
<body>
    @php
        $version = $data->version;
    @endphp

    {{-- Page 1: Header + Candidate Table + Totals --}}
    @include('pdf.partials.estimate-form-header', ['data' => $data, 'version' => $version])

    @if ($data->candidates->isEmpty())
        <p class="empty">No registered candidates at this school yet.</p>
    @else
        <table class="candidate-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Last Name</th>
                    <th>First Name</th>
                    <th>Voice Part</th>
                    <th>Grade</th>
                    <th>Fee</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($data->candidates as $index => $candidate)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $candidate->student->user->last_name }}</td>
                        <td>{{ $candidate->student->user->first_name }}</td>
                        <td>{{ $candidate->voicePart->name }}</td>
                        <td>{{ $candidate->student->grade ?? '—' }}</td>
                        <td>{{ number_format((($index + 1) * $data->registrationCents) / 100, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($data->truncated)
            <p class="truncated-note">Only the first {{ $version->max_registrants }} registered candidates (this Version's maximum) are listed above.</p>
        @endif

        <table class="totals-table">
            <thead>
                <tr>
                    @foreach ($data->voicePartCounts as $row)
                        <th>{{ $row['voicePart']->abbr }}</th>
                    @endforeach
                    <th>ePayments</th>
                    <th>Total Due</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    @foreach ($data->voicePartCounts as $row)
                        <td>{{ $row['count'] }}</td>
                    @endforeach
                    <td>{{ number_format($data->ePaymentsCents / 100, 2) }}</td>
                    <td>{{ $data->balanceDueCents < 0 ? '-' : '' }}${{ number_format(abs($data->balanceDueCents) / 100, 2) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    {{-- Membership Card page --}}
    @if ($data->membershipCardRequired)
        <div class="page-break">
            @include('pdf.partials.estimate-form-header', ['data' => $data, 'version' => $version])

            @if ($data->membershipCardImageUrl !== null)
                <img class="card-image" src="{{ $data->membershipCardImageUrl }}" alt="Membership card">
            @else
                <div class="card-placeholder">Attach membership card here&hellip;</div>
            @endif
        </div>
    @endif

    {{-- Mail-To page --}}
    <div class="page-break">
        @include('pdf.partials.estimate-form-header', ['data' => $data, 'version' => $version])

        <div class="mailto-box">
            <div class="mailto-label">Mail To:</div>
            <div class="mailto-body">
                @if ($data->mailToAddress !== null)
                    {{ $data->mailToAddress->recipient_name }}<br>
                    @if ($data->mailToAddress->organization_line)
                        {{ $data->mailToAddress->organization_line }}<br>
                    @endif
                    {{ $data->mailToAddress->address_line1 }}<br>
                    @if ($data->mailToAddress->address_line2)
                        {{ $data->mailToAddress->address_line2 }}<br>
                    @endif
                    {{ $data->mailToAddress->city }}, {{ $data->mailToAddress->geostate->abbr }} {{ $data->mailToAddress->zip }}
                @else
                    <span style="font-style: italic; color: #666;">Mailing address not yet configured — contact your Event Manager.</span>
                @endif
            </div>
        </div>
    </div>
</body>
</html>
