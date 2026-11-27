# Storage-included billing: plan

**Branch:** `feature/storage-included-billing` (off `dev` at fd48be5)
**Status:** approved 2026-11-25; built 2026-11-27 on this branch (not yet merged).
**Builds on:** `docs/plans/close-audition-invoice.md`

## Goal

Let the Founder mark an Event's per-registrant rate as **all-in**. Its platform
invoices then have no file-storage line. New chapters pay $4.00 per registrant
with storage included; existing Events keep their current terms.

Today `PlatformInvoiceService::send()` adds storage to every virtual Version
(Remote + Audio/Video uploads). Only the rate can be overridden per Event.

Not in scope: the first-year guarantee, the cancellation fee, the final billing
date, and changing the $2.75 default or $24.95 storage rate. Those fees are
invoiced by hand for now.

## Decisions

| # | Question | Recommendation |
|---|---|---|
| D1 | Where does the setting live? | On the Event, next to the rate override. It's a price term for the customer, so it should carry over to each year's Version |
| D2 | What does an all-in invoice show? | One registrant line, with "Virtual audition file storage included" under it, on virtual Versions only. The PDF and the email match |
| D3 | Snapshot it on the invoice? | Yes. Otherwise a resend after the setting changes would reprint different wording |
| D4 | Candidate date missing on an all-in virtual Version? | Send it. That date only prices storage, so it must not hold the invoice |
| D5 | Can "included" be used with a blank rate? | No. Require an explicit rate, so nobody silently gets $2.75 all-in. A typed 0 is allowed |
| D6 | Who can change it? | Founder only, in the existing Rates modal |

Alternatives rejected:

- **Per-Event storage rate (0 = included).** More general, but nobody needs a
  second storage price, and $0.00 would print as a line.
- **A date cutoff ("Events created after X").** Missouri's Event already exists
  and has its own terms, so a date can't tell the customers apart.

## Schema

One migration: `2026_11_25_000001_add_storage_included_to_billing.php`

| Table | Column | Definition |
|---|---|---|
| `events` | `platform_fee_includes_storage` | boolean, default false, after `platform_fee_per_registrant_cents` |
| `version_platform_invoices` | `storage_included` | boolean, default false, after `storage_rate_cents` |

Both default to false, so every existing Event and invoice keeps its behavior.
There is no backfill.

## Code changes

1. **`app/Models/Event.php`**: add to `#[Fillable]`; cast to boolean.
2. **`app/Models/VersionPlatformInvoice.php`**: add to `#[Fillable]`, the
   `@property` list and casts (boolean).
3. **`app/Services/PlatformInvoiceService.php`**
   - Add `storageIncluded(Version $version): bool`: `isVirtual()` and the Event's
     flag. A missing or soft-deleted Event means false, as `rateCents()` does.
   - Add `billsStorage(Version $version): bool`: `isVirtual()` and not included.
   - `send()`: use `billsStorage()` for the storage and Candidate date block, and
     write `storage_included` with the snapshot. Pass it to both founder-alert
     previews.
   - `sendTest()`: use `billsStorage()` for the storage line and the return value,
     so an all-in test doesn't warn about a missing Candidate date.
   - Leave `isVirtual()` and `storageCharge()` alone; `isVirtual()` is tested.
4. **`app/Support/Billing/PlatformInvoiceData.php`**: add
   `bool $storageIncluded`. `fromInvoice()` reads the snapshot; `preview()` takes
   it as a parameter. `amountCents` already works when `storage` is null.
5. **`resources/views/pdf/platform-invoice.blade.php`**: muted note under the
   registrant description when `storageIncluded`.
6. **`resources/views/mail/platform-invoice.blade.php`**: a "File storage:
   included" row when `storageIncluded`.
7. **`app/Livewire/Founder/PlatformInvoices.php`**
   - Add `public array $storageIncluded = []` (Event id => bool), loaded in
     `openRates()`.
   - `saveRates()`: validate `storageIncluded.*` as boolean. If it's checked and
     the rate is blank, add an error on `rates.{id}`: "Enter a rate for {event}
     to include storage." Save nothing on error.
   - Count an Event as changed once when either field changes. Change the toasts
     to "Saved billing terms for N events." and "No billing changes to save."
8. **`resources/views/livewire/founder/platform-invoices.blade.php`**
   - Rates modal: a `flux:checkbox` labeled "Storage included" on each row, with
     rows stacking on phones. Change the heading to "Rates and storage" and the
     help text to say what included means.
   - Invoice list: "Storage included" on the desktop row and the phone card
     when the snapshot says so. (The phone card doesn't show the existing "Incl. N
     mo storage" text; add that too.)
9. **`docs/plans/close-audition-invoice.md`**: replace "no per-Event override" in
   the storage table, update the schema section and link here.

## Behavior by case

| Version | Event setting | Storage line | Missing Candidate date |
|---|---|---|---|
| Virtual | Off (default) | Billed, as today | Held, as today |
| Virtual | Included | None; note shown | Sent |
| Not virtual | Off | None, as today | No effect |
| Not virtual | Included | None; no note | No effect |

- **Pending invoice:** terms are read at send time, so a change within the 24
  hours applies.
- **Sent or paid invoice:** unchanged, including resends and PDFs.
- **Invoice already held for a Candidate date:** if storage is then marked
  included, the existing Resend action sends it.
- **Zero registrants:** still void.

## Tests

`tests/Feature/Billing/PlatformInvoiceServiceTest.php`
1. An all-in virtual Version bills registrants only: 350 × 400 = 140000 cents,
   storage fields null, `storage_included` true. Override plus flag on the Event.
2. The PDF and email show the note and no storage charge.
3. An all-in virtual Version with no Candidate date is sent, with no founder alert.
4. A held no-Candidate-date invoice sends after the flag is turned on.
5. An all-in, non-virtual Version has `storage_included` false and no note.
6. Turning the flag on or off after sending doesn't change the resent invoice.
7. Turning the flag on while pending applies at send time.
8. `sendTest()` returns false for an all-in Version with no Candidate date.
9. Regression: a $3.00 override without the flag still bills storage (Missouri's
   terms).

`tests/Feature/Livewire/Founder/PlatformInvoicesTest.php`
10. Save and clear the flag; an unchanged save reports no changes.
11. Included with a blank rate errors and saves nothing; with 0 it saves.
12. The Rates modal loads stored values.
13. The list shows "Storage included" for a snapshotted invoice.

Keep new helper functions out, or prefix them: Pest helpers are global across the
suite. Then run the full test suite and PHPStan with no path arguments, and run
`composer dump-autoload` first if any class is added (none is planned).

## Deploy

Migrations are never automatic, and a push to `main` deploys production.

1. Merge to `dev`; migrate locally and on staging.
2. **Run the migration on production before pushing `main`.** Both columns are
   additive with defaults, so the current code ignores them. In the other order,
   sending an invoice would fail (and retry) until the columns exist. The
   production migration is run by hand, so confirm how the last one was done:
   either apply the SQL and insert the `migrations` row, or run the migration
   from a deployed build containing the file.
3. Push `main`, then check the Rates modal loads and every Event shows
   "Storage included" unchecked.
4. Send a test invoice for an all-in Event; it goes only to the Founder.

**No Event is switched on by the deploy.** Missouri stays off: its quote is $3.00
plus storage. Its $3.00 override is a separate Founder action in the Rates modal
and needs no code.

**Rollback:** revert the code; leave the columns. Turning the flag off restores
storage billing for anything unsent.

## Size

About half a day: one migration, eight source files plus the older plan doc, 13
tests. Risk is low: the default path is unchanged and regression-tested.

## Answers (user, 2026-11-25)

1. "Storage included" requires a typed rate (D5): **yes**.
2. Invoice wording "Virtual audition file storage included": **yes**.
3. New Events default to $4.00 all-in: **no**. The Founder sets each new chapter by hand.
