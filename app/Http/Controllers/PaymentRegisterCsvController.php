<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Livewire\Registrations\VersionDashboard;
use App\Models\Version;
use App\Support\Reports\CsvExport;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentRegisterCsvController extends Controller
{
    public function __invoke(Version $version): StreamedResponse
    {
        $teacher = Auth::user()->teacher;
        abort_if($teacher === null, 403);

        $rows = VersionDashboard::paymentRegisterRows($version, $teacher);

        return CsvExport::stream(
            "payment-register-{$version->id}.csv",
            ['Candidate', 'Date', 'Type', 'Amount', 'Reference', 'Status'],
            $rows->map(fn (array $row): array => [
                $row['candidate']->student->user->sort_name,
                $row['paidAt']->format('M j, Y'),
                $row['type'],
                number_format($row['amountCents'] / 100, 2),
                $row['referenceNumber'] ?? '',
                $row['status']->label(),
            ]),
        );
    }
}
