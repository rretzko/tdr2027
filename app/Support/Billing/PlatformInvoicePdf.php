<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

/**
 * Renders the platform invoice PDF — shared by PlatformInvoiceMail's
 * attachment and the Founder's "View PDF" download.
 */
final class PlatformInvoicePdf
{
    public static function render(PlatformInvoiceData $data): string
    {
        return Pdf::loadView('pdf.platform-invoice', ['data' => $data])
            ->setPaper('letter')
            ->output();
    }

    public static function filename(PlatformInvoiceData $data): string
    {
        return Str::slug(($data->isTest ? 'test-' : '').'invoice-'.$data->number.'-'.$data->versionName).'.pdf';
    }
}
