@php
    use App\Mail\PlatformInvoiceMail;
    use App\Support\Billing\PlatformInvoiceData;
@endphp
<x-mail::message>
@if ($variant === PlatformInvoiceMail::VARIANT_NO_RECIPIENTS)
# Invoice not sent: no Event Managers

**{{ $data->versionName }}** ({{ $data->eventName }}) was due to be invoiced, but the Version has no Event Manager to send it to.

The invoice is still scheduled and is flagged **Needs attention** on the Founder Invoices screen. Assign an Event Manager, then use **Resend** there to send it. The attached PDF shows the current numbers ({{ PlatformInvoiceData::money($data->amountCents) }}).
@elseif ($variant === PlatformInvoiceMail::VARIANT_NO_CANDIDATE_DATE)
# Invoice not sent: no Candidate date

**{{ $data->versionName }}** ({{ $data->eventName }}) is a virtual audition, so its invoice includes file storage, which starts one month before the Version's **Candidate** date. That date isn't set, so the invoice wasn't sent.

The invoice is flagged **Needs attention** on the Founder Invoices screen. Add the Candidate date on the Version's Dates tab, then use **Resend** there to send it. The attached PDF shows the registrant line only.
@else
@if ($variant === PlatformInvoiceMail::VARIANT_TEST)
**TEST: not a bill.** This preview was built from the Version's current numbers and was sent only to you.

@endif
# Invoice {{ $data->number }}

Thank you for using TheDirectorsRoom.com for **{{ $data->versionName }}**. Your invoice for platform usage is attached.

<x-mail::table>
| | |
|:--|--:|
| Registrants: {{ number_format($data->registrantCount) }} × {{ PlatformInvoiceData::money($data->rateCents) }} | {{ PlatformInvoiceData::money($data->registrantAmountCents) }} |
@if ($data->storage)
| File storage: {{ $data->storage->months }} {{ Str::plural('month', $data->storage->months) }} × {{ PlatformInvoiceData::money($data->storage->rateCents) }} | {{ PlatformInvoiceData::money($data->storage->amountCents()) }} |
@endif
| **Amount due** | **{{ PlatformInvoiceData::money($data->amountCents) }}** |
| Due date | {{ $data->dueAt->forDisplay()->format('F j, Y') }} |
</x-mail::table>

Please make checks payable to **{{ $data->company['payable_to'] }}** and mail them to:

{{ $data->company['name'] }}<br>
@foreach ($data->company['address_lines'] as $line)
{{ $line }}<br>
@endforeach

Please include the invoice number with your payment.

**It has been a pleasure working with you!**

Thank you,<br>
Rick Retzko<br>
{{ $data->company['name'] }}
@endif
</x-mail::message>
