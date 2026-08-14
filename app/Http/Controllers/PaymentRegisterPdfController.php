<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Livewire\Registrations\VersionDashboard;
use App\Models\Version;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class PaymentRegisterPdfController extends Controller
{
    public function __invoke(Version $version): Response
    {
        $teacher = Auth::user()->teacher;
        abort_if($teacher === null, 403);

        $rows = VersionDashboard::paymentRegisterRows($version, $teacher);

        return Pdf::loadView('pdf.payment-register', [
            'version' => $version,
            'teacher' => $teacher,
            'rows' => $rows,
        ])->download("payment-register-{$version->id}.pdf");
    }
}
