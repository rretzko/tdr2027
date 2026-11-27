# Close Audition → Platform Invoice to Event Managers

Branch: `feature/close-audition-invoice` (off `dev`). Status: **built 2026-11-16** — not yet committed/merged. Two build-time deviations from the plan below: a `needs_attention` boolean column (drives the "Needs attention" badge/filter for the no-Event-Manager case), and the founder-alert/no-recipients and resend paths use `PlatformInvoiceMail` variants rather than separate mailables.

## Goal

When a Tab Room Manager clicks **Close Audition** (`events/versions/{version}/tab-room/close`,
`App\Livewire\Events\TabRoom\CloseAudition::close()`), schedule a platform-usage invoice from
**MFR Holdings, LLC** that is emailed **24 hours later** to the Event Managers of that Version.
The Founder gets screens to list invoices, mark them paid, resend them and send test copies.

This is TDR's own bill to the organization. It is **not** the teacher-facing Estimate Form
(§5.13 of event-version-orientation.md), which bills candidate fees on the organization's behalf.

## Decisions (clarifying-question passes, 2026-11-16)

| Question | Decision |
|---|---|
| Pricing | **Per registrant, $2.75** (275 cents) default; per-Event override possible |
| Recipients | **Event Managers of the Version being closed only**, BCC the founder |
| Delivery | **Stored invoice record + email with a DomPDF attachment** |
| Re-close | **Send once, never resend automatically.** A later close after the invoice is sent does nothing (manual Founder resend is available) |
| Timing | **Sent 24 hours after the Close click** |
| Bill from / remit to | **MFR Holdings, LLC**, 45 Dayton Crescent, Bernardsville, NJ 07924, payable to **MFR Holdings, LLC**. **No payment link** (check by mail) |
| Terms | **Net 30**: due date = sent date + 30 days |
| Number format | **`TDR-{YYYY}-{NNNN}`**, e.g. `TDR-2027-0001`, sequential within the year (see note below) |
| Zero registrants | **Skip and mark `void`** |
| Fee-waived registrants | **Counted**: every registrant counts |
| Founder screens | **List invoices, mark paid, resend, test** (test mails go only to rick@mfrholdings.com) |

**Note on `{YYYY}`:** this is the calendar year the invoice is **sent** (America/New_York), so an
invoice sent in Dec 2026 is `TDR-2026-00NN`. If the year should instead come from the Version's
season or school year, change `PlatformInvoiceNumberer` (a one-line change).

## Virtual-audition file storage line (added 2026-11-16)

| Question | Decision |
|---|---|
| What is "virtual" | `versions.audition_type = remote` **and** `versions.upload_type` is `audio` or `video` (`PlatformInvoiceService::isVirtual()`) |
| Rate | **$24.95/month** (`config('billing.storage_fee_per_month_cents')` = 2495, env `BILLING_STORAGE_FEE_CENTS`); no per-Event storage rate, but an Event's rate can be marked **storage included** (all-in), which drops the line; see [storage-included-billing.md](storage-included-billing.md) |
| Window | one month before the Version's **Candidate** date `start_at` (`version_dates.date_type=candidate`, unique per Version; switched from the Teacher date on 2026-11-16 because Teacher dates vary too much) → one month after `versions.results_released_at` (no-overflow month math) |
| Counting | **partial months round up**, minimum 1 (`StorageCharge::monthsRoundedUp()`); e.g. Aug 15 → Mar 20 = 7 mo 5 days → **8 months** |
| No Candidate date | **held for attention**: not sent, `needs_attention` + `attention_reason`, founder alert (`no_candidate_date` mail variant); Founder Resend re-runs every check |
| Zero registrants | still **void**, no storage-only invoice |
| Billing timing | billed in full up front on the same invoice; the window end is known at send time because Close sets `results_released_at` |

Stored as a send-time snapshot on the invoice: `storage_access_at` (Candidate Access Date), `storage_close_at`
(Event Close Date), `storage_months`, `storage_rate_cents`. The source dates are stored rather than the padded
window, because the PDF prints them verbatim ("Candidate Access Date (mm/dd/yyyy) - Event Close Date (mm/dd/yyyy)
+ 1 month pre and post for testing and post-event review") and no-overflow month shifts aren't reversible;
`StorageCharge::startsAt()/endsAt()` derive the window (migration `2026_11_16_000003`, which also adds `attention_reason`).
`amount_cents` is the total (registrant line + storage line). Founder test sends include the storage
line, or omit it with a warning toast when the virtual Version has no Candidate date.

**Storage included (added 2026-11-27):** when the Event's `platform_fee_includes_storage` is on, a virtual
Version's invoice has no storage line, shows "Virtual audition file storage included" instead, and is sent
even with no Candidate date. The term is snapshotted as `version_platform_invoices.storage_included`
(migration `2026_11_25_000001`). Details: [storage-included-billing.md](storage-included-billing.md).

## Why a scheduler and not a delayed job

Prod runs on Vapor with SQS queues. SQS caps `DelaySeconds` at 900 (15 min), so
`Job::dispatch()->delay(now()->addDay())` throws on prod. Instead:

- `close()` writes a `pending` invoice row with `send_at = now() + 24h`.
- The scheduled command `invoices:send-due` (every 15 minutes, `withoutOverlapping()`) sends the
  rows that are due.

This survives deploys and queue flushes, and it lets a reopen during the 24-hour window cancel the
send. Note that `vapor.yml` enables the scheduler for **production only** (staging: false), so
staging never sends unless the command is run by hand.

## Lifecycle

```
Close clicked ──► no row?            create row: status=pending, send_at=now+24h
              ──► row pending/held?  status=pending, send_at=now+24h  (the 24h clock restarts)
              ──► row sent/paid/void? no-op (send once)

Reopen clicked ──► row pending?      status=held, send_at=null
               ──► otherwise         no-op

invoices:send-due (every 15 min):
   rows where status=pending AND send_at <= now
     ├─ Version no longer Closed?    → status=held (defensive; reopen should already have done this)
     ├─ transaction + lockForUpdate, re-check status=pending
     ├─ SNAPSHOT at send time: registrant_count, rate_cents, amount_cents, recipients
     ├─ registrant_count == 0?       → status=void, voided_reason='zero registrants', no email
     ├─ assign invoice number, issued_at=now, due_at=issued_at+30 days
     ├─ send PlatformInvoiceMail (PDF attached) to the Version's EMs, BCC founder
     └─ status=sent, sent_at=now
```

The count is taken **at send time**, not at click time. The 24-hour window exists for last-minute
corrections, so the invoice should reflect them.

## Schema

Version-owned, so it gets the `version_` prefix (naming convention).

**`version_platform_invoices`**
- `id`
- `version_id`: FK, **unique**. The database itself enforces "one invoice per Version"
- `number`: string, nullable until sent, unique
- `status`: string enum `PlatformInvoiceStatus` (`pending`, `held`, `sent`, `paid`, `void`)
- `closed_by_user_id`: FK users, nullable, nullOnDelete
- `send_at`, `sent_at`, `issued_at`, `due_at`: timestamps, nullable
- `registrant_count`, `rate_cents`, `amount_cents`: unsigned int, nullable (snapshot at send)
- `recipients`: json, nullable (emails actually sent to, for the audit trail)
- `paid_at`: timestamp, nullable; `paid_by_user_id`: FK users, nullable
- `payment_note`: string, nullable (e.g. "check #1234")
- `last_resent_at`: timestamp, nullable; `resend_count`: unsigned smallint, default 0
- `voided_reason`: string, nullable
- `storage_access_at`, `storage_close_at`, `storage_months`, `storage_rate_cents`, `attention_reason` (2026-11-16, above)
- `storage_included`: boolean, default false (2026-11-27, [storage-included-billing.md](storage-included-billing.md))
- timestamps

**Invoice numbering**: allocate inside the send transaction. Take
`max(number)` for the `TDR-{YYYY}-` prefix with `lockForUpdate`, add 1 and zero-pad to 4. The
unique index on `number` is the final guard against a collision.

**Rate storage**: `events.platform_fee_per_registrant_cents` (unsigned int, nullable), falling back
to `config('billing.platform_fee_per_registrant_cents')` = **275**. Next to it,
`events.platform_fee_includes_storage` (boolean, default false) marks the rate all-in; it requires a typed rate. It's per Event because the
organization is the customer. Only the Founder can edit it, from the Founder invoices screen (see
below), so it isn't exposed in VersionEdit for Event Managers.

## Config: `config/billing.php`

```php
'platform_fee_per_registrant_cents' => (int) env('BILLING_PLATFORM_FEE_CENTS', 275),
'terms_days' => 30,
'company' => [
    'name' => 'MFR Holdings, LLC',
    'address_lines' => ['45 Dayton Crescent', 'Bernardsville, NJ 07924'],
    'payable_to' => 'MFR Holdings, LLC',
],
'founder_email' => env('BILLING_FOUNDER_EMAIL', 'rick@mfrholdings.com'), // BCC on real sends + sole recipient of test sends
```

## Components

1. **Enum** `App\Enums\PlatformInvoiceStatus` (with `label()` / `color()` for Flux badges)
2. **Migrations**: the `version_platform_invoices` table and the `events.platform_fee_per_registrant_cents`
   column. ⚠ Prod migrations are **not** auto-run on deploy, so they must be run by hand after the main push.
3. **Model** `App\Models\VersionPlatformInvoice` (`belongsTo` Version / closedBy / paidBy;
   `Version::platformInvoice()` hasOne), with a factory
4. **Config** `config/billing.php` (above)
5. **Service** `App\Services\PlatformInvoiceService`
   - `scheduleOnClose(Version, User)`: create, or restart the clock, per the lifecycle
   - `holdOnReopen(Version)`
   - `registrantCount(Version)`: `Candidate::where('version_id')->whereIn('status', CandidateStatus::registrantStates())`
     (**not** `status=Registered`, which drains after results are released). Fee waivers are ignored.
   - `rateCents(Version)`: Event rate ?? config default
   - `recipientsFor(Version)`: the Version's "Event Manager" assignments
     (`VersionRoleAssignmentService::assignmentsForVersion()`), **not** `eventManagersForEvent()`,
     which spans every Version of the Event
   - `send(VersionPlatformInvoice)`: locked snapshot, number, mail, mark sent / void
   - `resend(VersionPlatformInvoice)`: re-mails the **stored snapshot** (same number, amount and due
     date, never recomputed) to the Version's **current** EMs plus a founder BCC; bumps `resend_count`
     and `last_resent_at`
   - `sendTest(Version)`: builds a preview from **live** numbers, watermarked "TEST — NOT A BILL"
     with the number `TDR-TEST-PREVIEW`, mailed **only** to `billing.founder_email`. Writes nothing to the database.
   - `markPaid(VersionPlatformInvoice, User, ?string note)` / `markUnpaid(...)`
6. **`App\Support\Billing\PlatformInvoiceData`**: a single value object built from either a stored
   invoice or a live preview, so the PDF, the mail and the Founder screen all render from the same shape
7. **Hook into `CloseAudition`**: call `scheduleOnClose()` in `close()` and `holdOnReopen()` in
   `reopen()`. Add one line to the close-audition blade above the button: "Closing schedules a
   platform-usage invoice ($2.75 × registrants) to this Version's Event Managers, sent 24 hours
   later. Reopening before then cancels it." Also extend the close toast with the same notice.
8. **Command** `App\Console\Commands\SendDuePlatformInvoices` (`invoices:send-due`), registered in
   `routes/console.php` as `->everyFifteenMinutes()->withoutOverlapping()`
9. **Mailable** `App\Mail\PlatformInvoiceMail` (Postmark transactional stream, the default; no
   broadcast header) with the PDF attached via `Attachment::fromData()`; an `isTest` flag adds a
   "[TEST]" subject prefix
10. **PDF view** `resources/views/pdf/platform-invoice.blade.php`, following the DomPDF conventions in
    `resources/views/pdf/estimate-form.blade.php`. Contents: MFR Holdings, LLC header and address,
    invoice number, issue date and due date (Net 30) in America/New_York, bill-to (Organization /
    Event / Version name), one line "Registrants: N × $2.75", the total, and "Make checks payable to
    MFR Holdings, LLC" with the mailing address. There's no payment link.
11. **Mail body view** `resources/views/mail/platform-invoice.blade.php`

## Founder screens

Route `GET /founder/invoices` → `App\Livewire\Founder\PlatformInvoices`, `founder.invoices`, inside
the existing `['auth', 'verified', 'founder']` group in `routes/web.php`. Add a sidebar item (icon
`document-currency-dollar`) to the Founder group in `resources/views/components/layouts/app.blade.php`.

**List**: a `flux:table` (cards below `md:`, the full table at `md:+` per the responsive-table
standard) with columns Number, Event / Version, Status badge, Registrants, Amount, Sent, Due, Paid.
- Filters: status (select, default "All") and search (number, event or version name).
- Sorted newest-first by `sent_at` / `send_at`. Overdue rows (status sent, due_at past) get a red "Overdue" badge.
- A summary strip above the table: Outstanding $, Overdue $, Paid this year $.
- `pending` rows show "Sends {send_at in America/New_York}".

**Row actions** (always visible, per the guarded-action rule; an invalid state is explained in the
post-click toast rather than hidden):
- **Mark paid** opens a modal with a paid date (default today) and an optional note (check #), then
  shows a success toast "Invoice TDR-2026-0003 marked paid." **Mark unpaid** is offered on paid rows.
- **Resend** opens a confirm modal listing the current EM recipients, then shows a toast
  "Invoice … resent to N Event Managers." On pending/held/void rows it gives the toast "Not sent
  yet — nothing to resend."
- **View PDF**: `GET /founder/invoices/{invoice}/pdf` streams the stored-snapshot PDF.

**Test send**: a "Send test invoice" button that opens a modal with a Version picker (closed Versions
first, but any Version is allowed), then `sendTest()` → the toast "Test invoice for {Version} sent to
rick@mfrholdings.com." Nothing is persisted.

**Event rate**: a small "Rates" modal/section that lists Events with their per-registrant override
(blank = the $2.75 default) and makes it editable, with a toast on save.

## Edge cases

- **No Event Managers on the Version** at send time: email the founder only with the subject prefix
  "[NO RECIPIENTS]", leave status `pending` and clear `send_at` (so it doesn't re-fire every 15 min),
  and show it on the Founder list as "Needs attention". The Founder can add an EM and then use Resend.
  (Resend on a pending row in this specific state performs the first real send.)
- **Zero registrants**: `void` with `voided_reason`, no email.
- **Reopen and re-close inside 24h**: the clock restarts from the latest close.
- **Reopen after the invoice was sent**: nothing changes; the invoice stands.
- **Sandbox Versions**: `close()` already aborts unless status is Active, so sandbox can't trigger this.
- **An Event Manager closes via VersionEdit's Status field**: does **not** trigger an invoice. Only
  the Close Audition button does, as requested.
- **Double send**: avoided by the unique `version_id`, the `lockForUpdate` re-check of `pending`,
  and `withoutOverlapping()`.

## Tests (Pest)

Prefix any helper functions uniquely (`platformInvoice…`), because Pest helpers are global across the suite.

`tests/Feature/Billing/PlatformInvoiceServiceTest.php`
- close creates a pending invoice with send_at = now+24h (`travelTo`)
- the command does not send before 24h and does send after (`Mail::fake()`)
- the count uses registrantStates() (Accepted/NotAccepted/NoShow/etc. count; Withdrew/Eligible don't); waived candidates count
- amount = count × 275; an Event override wins over the config default
- recipients = this Version's EMs only; an EM of a sibling Version does not receive it; the founder is BCC'd
- numbers `TDR-{YYYY}-0001`, `-0002`; they reset in a new year
- issued_at / due_at = +30 days
- zero registrants → void, no mail
- no EMs → founder-only mail, still pending, send_at cleared
- reopen inside 24h holds it; re-close restarts the clock; re-close after sent → no second invoice or email
- the PDF attachment is present and non-empty, and contains "MFR Holdings, LLC" and the number

`tests/Feature/Livewire/Founder/PlatformInvoicesTest.php`
- non-founder gets 403/redirect; founder sees the list
- mark paid / mark unpaid set and clear paid_at
- resend mails the stored snapshot (same number/amount even if the registrant count changed) and bumps resend_count
- resend on pending gives the toast, no mail
- the test send mails only rick@mfrholdings.com with "[TEST]" and writes no rows
- the rate override saves

Extend `tests/Feature/Livewire/Events/TabRoom/CloseAuditionTest.php`: close creates the row; reopen holds it.

Then run `composer dump-autoload`, the full suite, **full-project PHPStan (no path args)** and Pint.

## Deploy checklist

1. Run the migrations by hand on prod after the main push.
2. Optionally set `BILLING_FOUNDER_EMAIL` / `BILLING_PLATFORM_FEE_CENTS` in the Vapor env (the defaults are already right).
3. Use the Founder "Send test invoice" on a closed Version to check the PDF in a real inbox.
