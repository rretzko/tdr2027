# Electronic Payments — Implementation Plan

Scoped 2026-08-14 from a direct product-owner request plus a clarifying-question pass (see §0 below). Not yet built — this is the plan for tomorrow's work, following this project's usual "design doc → implementation" split (see `event-version-orientation.md` for the precedent this doc borrows its section style from).

---

## 0. What was asked, and what was clarified

**The problem as stated:** Electronic payments are already accepted today, off-platform, via PayPal and Stripe buttons that send the payer to the vendor with enough information to create a payment record. The vendor is supposed to send a status message back, but that step has never worked — payments have always been reconciled by hand. The goal for this iteration is to remove that manual step: real webhook-driven status updates.

**Business rules, as given:**
- Event Managers decide, per Version: whether electronic payment is accepted at all, and if so, whether it's available to teachers, students, or both.
- If accepted for students, individual teachers still decide whether *their* students get to use it — some teachers (especially lower grades) prefer cash handed to them directly.
- Regardless of payment method, the teacher is responsible to the organization for their school's balance being settled: `(candidates with status=registered) × versions.*_fee`.
- **Candidate registration status is never gated by payment.** This plan makes no changes to `CandidateStatus`/the registration checklist.
- Payments arrive from three sources, previously modeled in different, incompatible tables: (1) candidate e-payments, identifiable by `candidate_id`; (2) teacher e-payments, which are frequently a single lump sum covering multiple candidates — reconciling that split to actual candidates has been "haphazard at best"; (3) manual entries (checks, POs) recorded by the Registration Manager.
- A standard reconciliation process at the school and Version level would be an improvement.

**Clarifying answers that shape this plan:**

| Question | Answer |
|---|---|
| Who clicks "pay" for a student's fee, given there's no student portal today? | Deferred — StudentFolder.info is a **separate, next-phase project** ("next week"), a whole new module. **This iteration builds no student-facing payment UI.** The `epayment_student` config flag and teacher opt-in are still built now, but have no consumer until that module exists. |
| Do real vendor sandbox credentials exist to build/test against? | Yes, for both. **Stripe first** (built and verified end-to-end against real sandbox credentials), **then PayPal**. |
| Can a teacher's group payment carry structured per-candidate metadata for auto-splitting? | No — **lump sum only**, matching the "haphazard" history. Reconciling a group payment to specific candidates is and will remain a **manual allocation step**, not something a webhook can do automatically. |
| Build one vendor or both this iteration? | **Both** — Stripe fully verified first, PayPal built the same day behind the same interface. |

**Consequently, out of scope for this iteration** (flag, don't scaffold): any actual student/parent-facing payment button or portal (that's the StudentFolder.info project); automatic per-candidate splitting of a teacher's group payment (architecturally not possible without vendor-side line-item data that doesn't exist); refund initiation from within this app (webhook can *receive* a refund event and reflect it, but nothing here triggers a refund at the vendor).

---

## 1. Data model

### 1.1 Why a redesign, not just two new vendor tables

The existing `candidate_payments` (built 2026-08-04, §5.10; gained a manual writer 2026-08-13, §9 item 33 of the orientation doc) and `teacher_payments` (§5.10) tables are shaped for two different assumptions that no longer hold:

- `candidate_payments` assumes every payment is already known to belong to exactly one candidate.
- `teacher_payments` has **no candidate-level column at all** — it's a `(version, school, teacher)`-scoped total with no way to say which candidates it covers. This is the literal cause of "reconciling a group payment has been haphazard" — the schema itself never captured that information.

Both tables also predate real vendor integration — `payment_type` treats `Electronic` as a single case with no vendor, no transaction id, no webhook payload, nothing to make a webhook idempotent against.

**Proposed replacement: separate "a payment happened" from "which candidate fee(s) it satisfies."**

```mermaid
erDiagram
    payment_transactions ||--o{ payment_allocations : "reconciled into"
    payment_transactions {
        bigint id PK
        bigint version_id FK
        enum source "candidate_epayment | teacher_epayment | manual"
        enum vendor "stripe | paypal | null"
        string vendor_transaction_id "unique per vendor, null for manual"
        bigint payer_teacher_id FK "nullable"
        bigint payer_student_id FK "nullable, unused until StudentFolder.info"
        bigint school_id FK "nullable, snapshot at creation — triage only, see note below"
        unsignedInteger amount "cents, the FULL transaction amount"
        enum status "pending | completed | failed | refunded"
        enum payment_type "check|purchase_order|cash|other|electronic, source=manual only"
        string reference_number "nullable"
        text comments "nullable"
        json raw_payload "nullable, the webhook body, for audit"
        bigint recorded_by_user_id FK "nullable, manual entries only"
        timestamp paid_at "when the money actually moved"
    }
    payment_allocations {
        bigint id PK
        bigint payment_transaction_id FK
        bigint candidate_id FK
        unsignedInteger amount "cents, portion of the transaction for this candidate"
        bigint allocated_by_user_id FK "nullable"
        timestamp allocated_at
    }
```

**How each source populates this:**
- `candidate_epayment` (single candidate, teacher-initiated on their behalf this iteration — see §0) — one `payment_transactions` row, **one `payment_allocations` row created automatically at the same moment**, 100% of the amount. Always fully reconciled at creation; never enters the "needs reconciliation" queue.
- `manual`, when recorded against one specific candidate (this session's placeholder Payment-section form, §9 item 33) — same as above: auto-allocated 100% at creation.
- `teacher_epayment` (a teacher paying for multiple candidates in one transaction) and `manual` entries recorded at the school/teacher level (today's `teacher_payments` use case) — created with **zero allocations**. Sits in a "needs reconciliation" state — visible amount is `amount − sum(allocations)` — until a Registration Manager (or the paying teacher themselves, see §5 open question) allocates it across specific candidates, in any number of passes, never exceeding the transaction total. That cap is enforced in the allocation service (reject an allocation that would push `sum(allocations) > payment_transactions.amount`), not a DB constraint — a cross-row check like this isn't expressible as a column constraint in MySQL, and app-level enforcement is sufficient given allocation only ever happens through the one shared action (§3) whether a teacher or the Registration Manager triggers it.

This directly answers "a standard reconciliation process" (§4) and gives group payments a real, queryable, partially-completable state instead of the current all-or-nothing manual spreadsheet-equivalent.

**`school_id` is a creation-time snapshot, not a live reference — know which queries may use it.** An unallocated `teacher_epayment`/school-level `manual` transaction has no `payment_allocations` row yet, so there's no candidate to derive a school from live; `school_id` exists solely so the "Needs Reconciliation" queue (§3) can be filtered/searched by school *before* it's reconciled. It is captured once at creation from the payer's school at that moment and never updated — if a candidate (or their teacher) is later moved to a different school via `TeacherStudentTransferService` (§5.11 of the orientation doc), a payment made before that transfer keeps showing its original school here. This is fine for triage, but it means **every balance/reconciliation calculation (§3's school- and Version-level balance formulas) must derive "school" from the allocation's current `candidate_id → candidate.school`, never from `payment_transactions.school_id`** — the denormalized column is a pre-allocation convenience field only, not a source of truth once money is actually tied to a candidate.

**Migration/backfill for existing rows**, both tables have shipped in production since 2026-08-04/2026-08-13 and may hold real rows:
- Every `candidate_payments` row → one `payment_transactions` (`source = manual`, `vendor = null`) + one `payment_allocations` row (100%, `candidate_id` already known).
- Every `teacher_payments` row → one `payment_transactions` (`source = manual`) with **no allocations**. This surfaces every pre-existing group payment as needing reconciliation for the first time — a real, useful outcome of the fix, but flag it to the Registration Manager as an expected one-time backlog, not a bug, when this ships.
- `candidate_payments`/`teacher_payments` themselves are dropped **only after** `CandidateDetail`'s manual-entry form is cut over to write to the new tables (§4 step 5) — **not** right after the backfill runs. Both old tables are live in production today (`CandidateDetail`'s manual-entry writer, shipped 2026-08-12) and stay writable straight through the backfill step; dropping them before the writer is migrated would either break that in-production feature outright (writing to a table that no longer exists) or, if the drop were deferred without re-running the backfill, silently lose any payment recorded in the gap between the backfill and the cutover. See §4 for the exact ordering this implies.

### 1.2 Version-level configuration — repurpose `epayment_credentials`

That table is schema-only today (§5.2 of the orientation doc — no admin UI writes to it), so restructuring it is low-risk. Proposed rename to **`version_epayment_configs`** (the old name undersold what it now is — a feature-config record, not just a secret) with the exact properties given:

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | PK |
| `version_id` | foreignId | FK → versions; cascade on delete; unique |
| `vendor` | enum, nullable | `Stripe \| Paypal` — one vendor per Version (not both simultaneously — matches the literal singular "vendor (PayPal or Stripe)" property given); null = e-payment not accepted at all |
| `vendor_account_id` | string, nullable | The Event's merchant/account id at that vendor (was `epayment_id`) |
| `secret` | text, encrypted, nullable | API secret key — same `encrypted` cast the existing column already uses |
| `epayment_student` | boolean, default false | Event Manager: students may pay electronically (no consumer until StudentFolder.info exists) |
| `epayment_teacher` | boolean, default false | Event Manager: teachers may pay electronically |

New `VersionEdit` admin UI (a "Payments" tab, or folded into the existing Requirements tab — TBD at implementation time) to set these — today there is genuinely no admin screen for this at all.

### 1.3 Teacher opt-in — reuse and re-scope `version_teacher_epayment_opt_ins`

Built this session (§9 item 33) as a teacher+Version-scoped boolean. Keep it as-is structurally; re-scope its *meaning*: it now only matters when `version_epayment_configs.epayment_student` is true (an Event Manager who never turned on student e-payment makes every teacher's opt-in moot). No schema change needed — just re-wire the gating condition in `CandidateDetail` from "does an `epaymentCredential` row exist" to "does `version_epayment_configs.epayment_student` = true."

---

## 2. Vendor integration architecture

### 2.1 A vendor-agnostic interface, two real implementations

```
App\Services\Payments\
    PaymentGatewayContract.php   (interface)
    StripePaymentGateway.php     (implements, built + verified first)
    PaypalPaymentGateway.php     (implements, built second)
    PaymentGatewayFactory.php    (resolves the right one from Version::epaymentConfig->vendor)
    Dto/
        CheckoutSession.php      (readonly: redirectUrl, paymentTransactionId)
        WebhookEvent.php         (readonly: vendorTransactionId, status, amountCents, rawPayload)
```

```php
interface PaymentGatewayContract
{
    public function createCheckoutSession(Version $version, Collection $candidates, Teacher $payer): CheckoutSession;
    public function verifyWebhookSignature(Request $request): bool;
    public function parseWebhookEvent(Request $request): WebhookEvent;
}
```

Both `createCheckoutSession()` calls create a `payment_transactions` row (`status = pending`) **before** redirecting the payer to the vendor, so the webhook has something to find and update rather than needing to create the row itself from scratch (avoids a race between "user redirected to vendor" and "webhook arrives").

### 2.2 Stripe (first, real sandbox verification)

- New dependency: `stripe/stripe-php` (the official SDK; **confirmed 2026-08-14, §5 item 3** — current stable `v21.2.0`, re-verify exact version at implementation time).
- Checkout: **Stripe Checkout Sessions** (hosted, redirect-based) — matches "sends the user to the appropriate vendor with all the information necessary," simplest correct integration, no card data ever touches this app.
- Webhook: `POST /webhooks/payments/stripe`, verified via `\Stripe\Webhook::constructEvent()` against `.env('STRIPE_WEBHOOK_SECRET')` (**confirmed 2026-08-14, §5 item 4** — one app-wide secret, not per-Version).
- Relevant events: `checkout.session.completed` (→ `status = completed`, auto-allocate if single-candidate), `checkout.session.expired`/`payment_intent.payment_failed` (→ `status = failed`), `charge.refunded` (→ `status = refunded`).

### 2.3 PayPal (second, same day)

- New dependency: `paypal/paypal-server-sdk` (**confirmed 2026-08-14, §5 item 3** — current stable `2.3.0`, not abandoned; PayPal's older `paypal/paypal-checkout-sdk` and `paypal/rest-api-sdk-php` are both marked `! Abandoned !` on Packagist — do not use them).
- Checkout: PayPal Orders API v2 (`Create Order` → redirect to `approve` link → `Capture Order` on return, or the equivalent hosted-checkout button flow — confirm exact UX against the two vendors' current best-practice flow at implementation time, since Stripe and PayPal don't map 1:1 here).
- Webhook: `POST /webhooks/payments/paypal`, verified via PayPal's webhook-signature-verification API call against `.env('PAYPAL_WEBHOOK_SECRET')`/webhook id (not a local HMAC check like Stripe — PayPal's verification is itself a server-to-server API call; **confirmed 2026-08-14, §5 item 4** — one app-wide secret, not per-Version).
- Relevant events: `CHECKOUT.ORDER.APPROVED`/`PAYMENT.CAPTURE.COMPLETED` (→ completed), `PAYMENT.CAPTURE.DENIED` (→ failed), `PAYMENT.CAPTURE.REFUNDED` (→ refunded).

### 2.4 Shared webhook handling

- Both routes excluded from CSRF (standard for webhooks), rate-limited.
- Each route: verify signature → **respond 200 immediately** → dispatch a queued `ProcessPaymentWebhookJob` (this app already runs `QUEUE_CONNECTION=database`, no new infra needed) to do the actual `payment_transactions` update. Vendors retry on slow/non-200 responses; queuing keeps the HTTP response fast and makes retries harmless.
- **Idempotency**: `vendor_transaction_id` is looked up before writing — a retried webhook updates the existing row, never creates a duplicate. Enforce this with a real unique index scoped to `(vendor, vendor_transaction_id)` where not null, not just app-level discipline.
- `raw_payload` stores the full webhook body on every processed event — the audit trail this domain needs, and the thing that makes a "vendor said X, we recorded Y" support question answerable without guessing.

---

## 3. UI changes

- **`VersionEdit`** — new Payments config (§1.2): vendor select, account id, secret, two checkboxes (`epayment_student`/`epayment_teacher`).
- **`CandidateDetail`** — the existing placeholder "Record Payment" button/modal from this session (§9 item 33) gets replaced: a real "Pay Now" button when `epayment_teacher` is on, redirecting through `PaymentGatewayFactory`; the manual-entry form stays for check/PO/cash, now writing into `payment_transactions` + an auto-created single-candidate `payment_allocations` row instead of the old `candidate_payments` table.
- **`VersionDashboard`** (or a new dedicated screen) — a multi-select "Pay for selected candidates" action for a teacher's group payment, creating one `payment_transactions` row (`source = teacher_epayment`) covering the selected candidates' total, unallocated until reconciled. **Confirmed 2026-08-14 (§5 item 2):** the same screen (teachers don't have access to the Registration Manager Reports module) gets its own "Your Unreconciled Payments" section — any of *that teacher's* transactions with `amount > sum(allocations)`, with the same allocate-to-candidates action described below, scoped to their own roster only.
- **New "Payment Reconciliation" report** (extends the Registration Manager Reports module, §5.10; Registration-Manager-facing, all teachers/schools in the Version) — replaces the existing Payment Roster's `candidate_payments ∪ teacher_payments` union with a query over `payment_transactions`/`payment_allocations`:
  - A "Needs Reconciliation" queue: every transaction with `amount > sum(allocations)`, remaining balance shown, an allocate-to-candidates action — the same underlying action/component the teacher-facing version above uses, just unscoped.
  - **School-level balance**: `fees_due (registered candidates × (versions.registration + versions.participation), confirmed 2026-08-14, §5 item 1 — no housing) − sum(allocations for that school's candidates)` — "that school's candidates" resolved live via each allocation's `candidate_id → candidate.school`, **not** `payment_transactions.school_id` (§1.1's denormalized `school_id` is a pre-allocation triage field only, and goes stale after a `TeacherStudentTransferService` move).
  - **Version-level rollup**: sum across every school.

---

## 4. Phased build order (for tomorrow)

1. Migrations: `payment_transactions`, `payment_allocations`, restructure `epayment_credentials` → `version_epayment_configs`, keep `version_teacher_epayment_opt_ins` as-is. Models + factories.
2. Backfill migration for existing `candidate_payments`/`teacher_payments` rows into the new tables (§1.1), verified against a copy of real data. **The old tables are not dropped in this step** — they stay in place and stay live; `CandidateDetail`'s manual-entry form keeps writing to them, unchanged, through steps 3-4.
3. `PaymentGatewayContract` + DTOs + `PaymentGatewayFactory` (interface first, so both vendors are built against the same contract from the start).
4. `StripePaymentGateway` — checkout session creation, webhook route + signature verification + `ProcessPaymentWebhookJob`. **Verify end-to-end against your real sandbox before moving on** — this is the one vendor we can genuinely confirm works today.
5. `CandidateDetail` "Pay Now" (single candidate, Stripe) wired to the real flow; manual-entry form migrated to the new schema. **In this same deploy**: re-run/diff the step-2 backfill (to catch anything written to the old tables in the interim), then drop `candidate_payments`/`teacher_payments`. Backfill, cutover, and drop must ship together — never split across separate deploys — so there is no window where the old tables exist unverified, and no window where the old form points at a table that's already gone.
6. Group-payment initiation UI + the "Needs Reconciliation" allocation screen — build this against Stripe's real transactions first, since it's the harder, more novel piece (the group-payment case is the one your own words called "haphazard").
7. `PaypalPaymentGateway` — same contract, same webhook job (source-agnostic once the DTO is parsed), built and wired the same day.
8. Payment Reconciliation report (school + Version balances), replacing Payment Roster.
9. `VersionEdit` Payments config tab.
10. Regression pass: full test suite, PHPStan, and a manual walk-through of both a single-candidate Stripe payment and a group payment reconciled through the new allocation screen.

---

## 5. Open questions / assumptions to confirm before or during implementation

These are judgment calls made to keep this plan concrete — flagged, not silently decided:

1. ~~**Which fee columns make up the balance owed?**~~ **Confirmed 2026-08-14: `registration + participation` only, no `housing`.** This replaces the existing "Fees due" formula (§5.10 of the orientation doc), which only used `versions.registration` — that report's query needs updating to add `participation` alongside it wherever "fees due"/balance owed is computed, not just in the new Payment Reconciliation report. ~~Still open: whether `epayment_surcharge` applies only to electronic transactions~~ **Confirmed 2026-08-14: yes** — `epayment_surcharge` is additive on top of `registration + participation` for a "Pay Now" checkout total (covers vendor fees) and is excluded from the balance-owed figure for manual payments (check/PO/cash) and from the base "fees due" calculation itself. A candidate's balance owed is always `registration + participation`, regardless of payment method; the surcharge only ever appears as an extra line item at electronic checkout time, never as part of what's "owed."
2. ~~**Who's allowed to perform reconciliation allocation?**~~ **Confirmed 2026-08-14: both** — a teacher can allocate their own group payments (they have the freshest knowledge of who's in it), and the Registration Manager can allocate/adjust any transaction in the Version. The allocation screen (§3) needs a teacher-scoped view (their own transactions only) alongside the Registration Manager's full-Version view, not just one shared unscoped screen.
3. ~~**Exact Stripe/PayPal Composer package names and API versions**~~ **Confirmed 2026-08-14, via `composer show`/`composer search` against Packagist:** Stripe is `stripe/stripe-php` (current stable `v21.2.0`, actively maintained — official SDK, name unchanged from the guess in §2.2). PayPal is `paypal/paypal-server-sdk` (current stable `2.3.0`, not flagged abandoned) — **not** `paypal/paypal-checkout-sdk` or `paypal/rest-api-sdk-php`, both of which Packagist marks `! Abandoned !`; `paypal/paypal-server-sdk` is PayPal's current officially-maintained REST SDK (repo: `paypal/PayPal-PHP-Server-SDK`) and supersedes both. Re-verify the exact minor/patch version at implementation time (a day's drift is fine; an abandoned package is not).
4. ~~**Webhook secret storage**~~ **Confirmed 2026-08-14: one app-wide webhook signing secret per vendor**, in `.env` (`STRIPE_WEBHOOK_SECRET`, `PAYPAL_WEBHOOK_SECRET`) — not per-Version, not in `version_epayment_configs`. Both vendor webhook endpoints (§2.2/§2.3/§2.4) are single app-wide routes regardless of which Version/Event a payment belongs to; `version_epayment_configs.secret` (the vendor **API** secret key, §1.2) is unrelated and stays per-Version as originally proposed — this only resolves which secret verifies the *webhook signature*, not which credentials create the checkout session.
5. ~~**Payment notification**~~ **Confirmed 2026-08-14: a toast, not email.** No new notification infra (mailables, queued notifications) for this iteration. Mechanics: the payer lands back on `CandidateDetail`/`VersionDashboard` after vendor checkout redirect (§2.2/§2.3) — if the queued `ProcessPaymentWebhookJob` (§2.4) has already flipped the transaction to `completed` by then, show the success toast on that render; if the webhook is still lagging, no toast fires and the payer falls back to seeing the transaction's state next time they view the page (or the reconciliation report, §3) — no polling or broadcasting is built to guarantee the toast always appears. Registration Manager gets no separate notification; the reconciliation report (§3) is their view into new payments.
6. ~~**`version_epayment_configs.vendor` really single-choice?**~~ **Confirmed 2026-08-14: yes, keep single-choice per Version** — the proposed unique-per-Version row (§1.2) stands as originally designed, no schema change. A Version cannot offer both Stripe and PayPal simultaneously in this iteration.
