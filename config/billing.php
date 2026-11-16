<?php

declare(strict_types=1);

/*
| TDR's own platform-usage billing (MFR Holdings, LLC → each Event's
| organization). Not candidate/registration fees — those live in
| version_fees. See docs/plans/close-audition-invoice.md.
*/

return [

    // Default per-registrant rate in cents; an Event's
    // events.platform_fee_per_registrant_cents overrides it.
    'platform_fee_per_registrant_cents' => (int) env('BILLING_PLATFORM_FEE_CENTS', 275),

    // Virtual-audition file storage, per month (partial months round up).
    // Applies only when a Version is audition_type=remote AND
    // upload_type=audio|video.
    'storage_fee_per_month_cents' => (int) env('BILLING_STORAGE_FEE_CENTS', 2495),

    // Hours between the Close Audition click and the invoice email.
    'send_delay_hours' => 24,

    // Net terms: due date = issue date + this many days.
    'terms_days' => 30,

    'company' => [
        'name' => 'MFR Holdings, LLC',
        'address_lines' => ['45 Dayton Crescent', 'Bernardsville, NJ 07924'],
        'payable_to' => 'MFR Holdings, LLC',
    ],

    // BCC on every real send, and the only recipient of Founder test sends.
    'founder_email' => env('BILLING_FOUNDER_EMAIL', 'rick@mfrholdings.com'),

];
