<?php

declare(strict_types=1);

namespace App\Http\Controllers\Founder;

use App\Enums\PlatformInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\VersionPlatformInvoice;
use App\Support\Billing\PlatformInvoiceData;
use App\Support\Billing\PlatformInvoicePdf;
use Illuminate\Http\Response;

/**
 * Founder "PDF" link: the stored send-time snapshot of an issued platform
 * invoice, exactly as emailed. Route sits in the 'founder' middleware group.
 */
class PlatformInvoicePdfController extends Controller
{
    public function __invoke(VersionPlatformInvoice $invoice): Response
    {
        abort_unless(in_array($invoice->statusEnum(), PlatformInvoiceStatus::issuedStates(), true), 404);

        $data = PlatformInvoiceData::fromInvoice($invoice);

        return response(PlatformInvoicePdf::render($data), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.PlatformInvoicePdf::filename($data).'"',
        ]);
    }
}
