# Per-Student Fee Waivers

**Status:** Steps 1–7 built and committed on `feature/student-fee-waivers` (2026-11-10). Not yet merged, browser-verified, or migrated on production.
**Branch (when work starts):** `feature/student-fee-waivers` off `dev`

## Request

A customer wants the Event Manager to be able to waive a fee for an individual
student. The waiver must show up on the teacher's registration forms and
reports, and on the Event Manager's and Registration Manager's reports.

## Decisions (confirmed 2026-11-10)

| Question | Decision |
|---|---|
| Which fees | Each `FeeType` separately: Registration, Participation, Housing |
| Amount | Full waiver only. A waived fee is $0, with no partial overrides |
| Who / where | Event Manager only, from a "Waive fees" action on each row of the Participating Candidates report. A reason is required. Registration Managers can see waivers but can't change them |
| Already paid | Allowed. The payment then shows as a credit/overpayment. The EM refunds it outside the app or reallocates it with the existing allocation UI |

## Data model

New table `candidate_fee_waivers`. It belongs to the candidate, not the
version, so it doesn't need a `version_` prefix:

| column | notes |
|---|---|
| `id` | |
| `candidate_id` | FK → candidates, cascade on delete |
| `fee_type` | `FeeType` enum value (string) |
| `reason` | text, required |
| `waived_by_user_id` | FK → users, null on delete |
| `timestamps` | `created_at` is the waived-at time |

- Unique on `(candidate_id, fee_type)`. A row existing means the fee is waived.
  Deleting the row removes the waiver.
- Model `CandidateFeeWaiver` (with factory). Add `Candidate::feeWaivers()` (HasMany)
  and a `Candidate::hasFeeWaived(FeeType)` helper that reads the eager-loaded relation.
- Audit trail for removed waivers is **not** in scope. If it's wanted later,
  switch to soft deletes plus a partial-unique workaround.

## Core refactor: one place that computes what a student owes

The amount a candidate owes is computed separately in each of these places
today. Each one would have to learn about waivers, so they should all go
through one service first:

| Site | What it computes |
|---|---|
| `app/Livewire/Events/Reports/ParticipatingSchools.php:264` | `$dueForCandidate` closure (reg + participation) |
| `app/Livewire/Events/Reports/PaymentReconciliation.php:267` | the same closure, duplicated |
| `app/Livewire/Registrations/CandidateDetail.php:716,745` | teacher's per-student due + housing |
| `app/Livewire/Sfdi/Events/Show.php:710,733` | student's own due + housing |
| `app/Support/EstimateFormData.php:98` | `count × registration` for the estimate PDF |
| `app/Services/DirectorWeeklyUpdateService.php:94` | `registered × registration` |
| `app/Services/VersionScorecardService.php:73` | scorecard due/outstanding |
| `app/Services/Payments/{Paypal,Square}PaymentGateway.php:71` | `amountForCheckout(feeType, count)` |

**New `App\Services\CandidateFeeCalculator`:**

- `feeCents(Version, Candidate, FeeType): int` returns the configured fee, or 0
  if that fee is waived for this candidate.
- `dueCents(Version, Candidate): int` returns registration, plus participation
  once `participationFeePayable()`, with waivers applied. It replaces the
  duplicated closures.
- `isWaived(Candidate, FeeType): bool`
- Callers eager-load `feeWaivers` so reports don't run N+1 queries.

Do this refactor as its own first commit, with **no behavior change**, checked
by the existing test suite. Waivers then go in as a small second commit.

## Behavior changes

### Event Manager: setting waivers
- **Participating Candidates report** (`Events/Reports/ParticipatingCandidates`):
  - Add a "Waive fees" row action that opens a Flux modal with one checkbox per
    FeeType, using the Version's configured amounts as labels (e.g.
    "Registration Fee ($20.00)"), and a required reason field.
  - Hide checkboxes for fee types the Version charges $0 for.
  - Save fires a personalized `Flux::toast` ("Fees waived for Jane Doe"), per
    the CRUD modal toast rule.
  - Rows with a waiver get a "Fee waived" badge that names which fees, with the
    reason in a tooltip.
  - Add a filter "Fee waivers: Any / Waived only".
  - Authorization: EM role through `VersionRoleAssignmentService`. Registration
    Managers see the badge but not the action, and the server-side check rejects them.
- Turning on a waiver for a fee that already has completed allocations is
  allowed. The modal shows an advisory: "$20.00 has already been paid for this
  fee. It will show as a credit."

### Checkout (teacher group pay + student self-pay)
- In the `VersionDashboard` roster, don't offer the checkbox for a candidate
  whose active FeeType is waived. Show "Waived" in its place.
  `payForSelected()` also filters waived candidates out defensively, so
  `amountForCheckout(feeType, count)` stays correct without changes.
- In `Sfdi/Events/Show`, replace the Pay Now button for a waived fee type with
  "Waived by event". `payNow()` aborts with 422 if the fee is waived.

### Teacher-facing (TDR)
- **VersionDashboard roster:** the Due/Paid columns show $0 plus a "Waived" badge.
- **CandidateDetail:** the payment panel shows "Registration Fee: Waived" and
  so on for each waived fee.
- **Estimate form PDF** (`EstimateFormData` + `pdf/estimate-form.blade.php`):
  rows used `($index + 1) × registration` as a running total. **Built
  (2026-11-10):** the running total is kept, as on the legacy paper form, but
  computed from each student's actual fee. A waived row shows "Waived" and
  adds $0, so the last row still equals the subtotal, and `balanceDueCents`
  follows.
- **Candidate Application PDF** (`CandidateApplicationData`): for a waived fee,
  print "Waived" instead of the amount.
- **Director Weekly Update email:** due becomes the sum of
  `feeCents(Registration)` over registered candidates.

### Event Manager / Registration Manager reports
- **Participating Schools** and **Payment Reconciliation**: due comes from the
  calculator. Add a "Waived" column (count and $ waived per school) plus a
  version total, so the EM can see the revenue impact.
- A negative balance (paid more than owed) shows as **"Credit $X.XX"**, not
  "-$X.XX". The `balanceFilter` "due" option already uses `> 0`. Add a
  "credit" filter option.
- **Version Scorecard** and the **Monday Morning Scorecard email**: due and
  outstanding drop by the waived amounts, and a "Waived" line is added.
  Existing `version_scorecard_snapshots` rows are history and are **not**
  recomputed.

### Student-facing (SFDI)
- The student sees "Registration Fee: Waived" and no Pay Now button. The
  reason is **not** shown to students or teachers; only the EM and Registration
  Managers see it.

## Edge cases
- **Version cloning:** waivers belong to candidates, so they're never cloned.
  `VersionCloningService` needs no changes.
- **Student merge:** `StudentMergeService` moves candidate payments onto the
  surviving candidate. When both candidates exist in the same Version, it must
  also carry waivers over (union by fee type). Add a test.
- **Candidate withdrawn:** waiver rows stay but have no effect, since withdrawn
  candidates are already excluded from due.
- **Fee amount changed later:** nothing to do. A waiver means $0 whatever the
  configured fee is.

## Testing (Pest)
- Calculator: each FeeType waived or not, participation timing gate, $0-fee version.
- Refactor commit: the existing report, dashboard, and payment tests still pass unchanged.
- EM can waive and remove waivers. A Registration Manager and a teacher get
  403. A reason is required.
- Checkout: a waived candidate is excluded from `payForSelected`. Student
  `payNow` on a waived fee returns 422.
- Reports: Participating Schools and Payment Reconciliation due, waived, and
  credit math. The estimate form subtotal with one waived row.
- Merge carries waivers over.
- Then PHPStan, full project, no path args.

## Implementation order
1. Migration, model, factory, relation (`composer dump-autoload`).
2. `CandidateFeeCalculator` and refactor all 8 sites with no behavior change. Commit.
3. EM waive modal on Participating Candidates, plus auth and toast.
4. Checkout exclusions (teacher + student).
5. Teacher-facing displays (dashboard, CandidateDetail, estimate PDF, application PDF, weekly email).
6. EM/RM reports (schools, reconciliation, scorecard + email), with credit display.
   **Also (added 2026-11-10):** put the "Waive fees" action on Payment
   Reconciliation's per-school detail, reusing `CandidateFeeWaiverService` and
   `canWaiveFees()`. Participating Candidates lists only `Registered`
   students, but Participation and Housing are charged after close, when
   students are `Accepted`. So once a Version closes, there is currently
   nowhere to waive those fees. Waivers set before close do carry over.
   **Built (2026-11-10):**
   - Payment Reconciliation has a per-school "Fee waivers" list (all
     fee-eligible statuses, Accepted included). It uses the shared
     `ManagesFeeWaivers` trait and `partials/fee-waiver-modal`, now also used
     by Participating Candidates.
   - A Waived column and total appear on both reports, and their PDFs, only
     once the Version has any waivers. The reconciliation CSV always has
     Waived, as its last column.
   - Credits were already shown as "$X overpaid" (never "-$X"), and an
     Overpayment filter already existed, so neither was changed.
   - The scorecard and Monday email show a Waived line when non-zero. It
     isn't stored on `version_scorecard_snapshots`, so no migration.
7. Student merge carry-over.
8. Tests and PHPStan throughout. The user does browser verification.
