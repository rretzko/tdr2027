# StudentFolder.info Module — Implementation Plan

Scoped 2026-08-17 from `StudentFolder Module.docx` (source: `C:\Users\RickRetzko\Documents\products\tdr2027\docs\StudentFolder Module.docx`) plus a clarifying-question pass with the product owner (§0 below). Not yet built — this is the design doc for the next implementation session, following this project's usual "design doc → implementation" split (see `event-version-orientation.md` / `epayment-integration.md` for precedent).

**Headline finding from research: this is overwhelmingly a UI + write-path build, not a new data model.** Every field the source doc asks for already exists in the schema (`students`, `home_addresses`, `emergency_contacts`, `candidates`, `version_dates.candidate`, `version_pitch_files`, `version_applications`, `version_epayment_configs.epayment_student`, etc.) — much of it was built specifically *anticipating* this module (see `epayment-integration.md` §0/§6: "`epayment_student` has no consumer yet... StudentFolder.info... is what will eventually use it"). The work here is almost entirely: (1) a student-facing registration/profile UI that writes to tables teachers already write to today, and (2) replacing several explicit "teacher does it on the student's behalf" stopgaps with the real thing now that a student portal exists.

---

## 0. What was asked, and what was clarified

**The source doc, condensed:** Students log into StudentFolder.info with email/password (own credentials, no social login — confirmed architectural decision). They maintain three static sections (Biography, School, Emergency Contact(s)) and see one dynamic section (Events) driven by which teacher(s) they're linked to. For each eligible Event Version, they complete Candidate requirements (whatever subset of birthday/shirt size/height/home address/emergency contact the Version requires), then Registration requirements (voice part, program name, application signing, file uploads if remote audition, pitch files for reference), and pay fees electronically if their teacher has opted them in.

**Clarifying answers that shape this plan:**

| Question | Answer |
|---|---|
| No Parent/Guardian account type exists — how does the eApplication get a parent signature? | **Student self-attests for both.** The student checks two boxes themselves ("I have signed" / "my parent/guardian has reviewed and approved") — no separate parent-facing flow, no email-signature-link mechanism. Single account, same two `candidates` columns (`application_candidate_signed_at`/`application_parent_signed_at`) already used by the teacher stopgap. |
| Can a student add a school/teacher that isn't already in the system? | **No — existing only, direct join.** A student may only pick from schools/teachers already onboarded (via `TeacherOnboardingWizard`, active + verified `school_teacher` rows). Selecting them creates the `school_student`/`student_teacher` links immediately, the same shape as a teacher adding an existing student today (`Students/Index.php`) — no pending-approval step, no "my school isn't listed" fallback. |
| The source doc's fee-eligibility rules include a Housing fee; the current e-payment system (`FeeType`, checkout, balance calculations) only handles Registration/Participation — Housing was explicitly deferred when e-payments were built. | **Add Housing as a full third `FeeType`** this phase — extend the enum, `Version::housingFeePayable()`, `VersionFee::amountForCheckout()`, checkout, and balance/reconciliation math to match Registration/Participation's existing shape. |
| Should student self-service edits (voice part, program name, withdrawal, home address, emergency contacts, uploads, signatures) be immediate or require teacher approval first? | **Direct, immediate** — same as a teacher's own edits today. No new pending-change/approval state. The teacher retains full visibility and override via the existing `CandidateDetail` page (both roles can edit the same rows). |

**Consequently, explicitly out of scope for this phase** (flag, don't scaffold): multi-domain routing so `studentfolder.info` serves its own domain (stays the existing deferred item — path-based `/sfdi/*` under one app continues, see `project_architecture_decisions.md` item 7); a Parent/Guardian account type or any parent-facing flow; a "request my school be added" pathway; teacher-approval gating on any student self-service action; removing the teacher's existing ability to certify/sign/edit on a candidate's behalf (both roles stay live — see §5.6).

---

## 1. Reuse map — what StudentFolder.info leverages from TheDirectorsRoom.com as-is

Per the product owner's explicit instruction to leverage the teacher portal's existing work as much as possible, here is what needs **zero or near-zero change**, versus what needs **extension**, versus what is genuinely **net-new**.

### 1.1 Reused as-is (read or write, no changes needed)

- **Schema**: `students` (height/birthday/shirt_size/voice_part_id), `home_addresses`, `emergency_contacts`, `school_student`/`student_teacher` pivots, `candidates` + all its columns, `version_dates` (the `Candidate` `VersionDateType` case already exists — `app/Enums/VersionDateType.php:11`), `version_pitch_files`, `version_applications`, `version_epayment_configs.epayment_student`, `version_teacher_epayment_opt_ins`.
- **`App\Services\EligibilityService`** (`app/Services/EligibilityService.php`) — the eligible-students-for-a-version query. Student-side "which events am I eligible for" is the same underlying rule set (invited/obligated teacher, active+verified school, grade/class_of match), just entered from the student's own linked teachers instead of a teacher's roster.
- **`App\Services\CandidateService`** (`enroll()`, `withdraw()`, `recalculateStatus()`) — reused directly; a student's self-service voice-part/program-name/signature edits all need to re-run `recalculateStatus()` exactly like the teacher's `CandidateDetail` actions already do.
- **`App\Services\AutoEnrollmentService`** — when a student's self-service school/teacher join creates a `student_teacher` row, `StudentTeacherObserver` already fires `enrollNewStudentIntoInvitedActiveVersions()` (`app/Services/AutoEnrollmentService.php:83`). **No new enrollment logic is needed** — a student joining a teacher's roster auto-creates `Candidate` rows for every currently-open, eligible Version exactly as it does when a teacher adds the student today.
- **`App\Concerns\HasCandidateChecklist`** (`app/Concerns/HasCandidateChecklist.php`) — the Candidate-requirements checklist (program name, emergency contact, birthday, height, home address, shirt size, uploads, application signatures) is Version-config-driven and candidate-scoped, not teacher-scoped. Reused verbatim for the student's own "what's left" view.
- **`App\Support\ClassOfCalculator::classOfFromGrade()`** — for deriving `school_student.class_of` from the grade a student selects at school-join time (the source doc's "Grade... and class_of value is derived from grade").
- **`App\Support\NameFormatter`**, **`App\Support\SchoolMatcher`** (search-only, no create path per §0), **`App\Support\EmailVerifiabilityChecker`**, **`App\Support\PhoneNormalizer`**.
- **`App\Support\CandidateApplicationData`** (`fromCandidate()`) and the shared `resources/views/candidate-application/document.blade.php` partial — the exact same merged-document renderer teachers/PDF already use.
- **`App\Services\Payments\PaymentGatewayFactory`** / `PaymentGatewayContract` / `SquarePaymentGateway` / `PaypalPaymentGateway` — vendor-agnostic checkout session creation, already built and verified against real sandboxes (`epayment-integration.md` §2). A student "Pay Now" is the same `createCheckoutSession()` call `CandidateDetail`'s teacher-initiated Pay Now already makes, just invoked by the candidate instead of the teacher.
- **`CandidateUploadFile`** model + S3 upload conventions (`WithFileUploads`, per-slot `version_upload_files`) — the student is simply a second, additional uploader alongside the teacher.
- **`GuardsAcceptedObligations`** trait pattern — not reused verbatim (obligations are teacher-only, a student has no obligation to accept), but its shape (a `mount()`-time redirect guard) is the template for the student-side "is this candidate row even mine" guard.

### 1.2 Extended (existing feature, teacher-only today, needs a student-facing counterpart)

| Existing teacher feature | File | Student-facing counterpart needed |
|---|---|---|
| `CandidateDetail`'s voice-part edit (constrained to `Version::availableVoiceParts()`) | `app/Livewire/Registrations/CandidateDetail.php` | Same constraint, candidate-scoped, invoked by the student |
| `CandidateDetail`'s program-name edit | same | Same |
| `CandidateDetail`'s home address / emergency contact edit modals | same | Same |
| `CandidateDetail`'s `toggleApplicationCandidateSigned`/`toggleApplicationParentSigned` (`EApplication` mode) | same | Per §0, the **student** now performs both toggles (self-attesting for the parent); **the teacher's existing toggle stays live too** (see §5.6 — deliberately not removed, so a teacher can still certify on behalf of a student who hasn't joined the portal yet) |
| `CandidateApplicationPdfController` (ownership check is currently teacher-only: `403` unless the requesting teacher owns the candidate) | `app/Http/Controllers/CandidateApplicationPdfController.php` | Add a student-ownership branch (`candidate->student->user_id === auth()->id()`) alongside the existing teacher check |
| `Registrations/PitchFiles` read-only view | `app/Livewire/Registrations/PitchFiles.php` | New candidate-facing equivalent, additionally enforcing `pitch_file_visibility` (see §5.5 — **not enforced on the teacher-facing page today either**; fixing both in the same pass) |
| `VersionDashboard`'s single-candidate "Pay Now" (`epaymentTeacherReady()`) | `app/Livewire/Registrations/VersionDashboard.php` | New candidate-facing Pay Now, gated on `Version::epaymentStudentEnabled()` **and** that candidate's teacher having opted in (`VersionTeacherEpaymentOptIn`) — exactly the condition the source doc states: "If the candidate's teacher has opted in... display the appropriate payment form" |
| Candidate withdrawal (`CandidateService::withdraw()`, teacher-only UI today) | `CandidateDetail` | Add a student-facing "Withdraw" action calling the same service method (§0: direct, immediate) |
| `EmailVerifiabilityChecker` (used at registration to set `email_unverifiable`) | `app/Livewire/Auth/StudentRegister.php` | **New**: wire the same checker into `ForgotPassword` to show the source doc's required advisory (§3 below) — today `ForgotPassword.php` shows no such message at all |
| `FeeType` (Registration/Participation only) | `app/Enums/FeeType.php`, `Version.php`, `VersionFee.php` | Add `Housing` case + `Version::housingFeePayable()` (§0, §6) |

### 1.3 Net-new

- Student registration completion: school/teacher self-join UI (§4.2).
- Student profile pages: Biography, Home Address, Emergency Contact(s) as first-class self-editable sections (today these only exist as teacher-edited fields on the `Candidate` checklist flow — a student has no page to set them **before** having a candidate row at all, e.g. before their first eligible Version even opens).
- Student "Events" dashboard/list (the source doc's dynamic section) — the student-side equivalent of `Registrations/Index`.
- Student per-Version registration page — the student-side equivalent of `CandidateDetail`, scoped to the student's own candidacy.
- `ForgotPassword` advisory copy (§3).
- `Housing` `FeeType` plumbing (§6).
- `pitch_file_visibility` enforcement (§5.5).

---

## 2. Data model changes

**Genuinely new columns/tables: none required for the Biography/School/Emergency Contact/Candidate-requirements surface — all of it already exists.** Two small additions:

### 2.1 `FeeType::Housing`

```php
enum FeeType: string
{
    case Registration = 'registration';
    case Participation = 'participation';
    case Housing = 'housing';          // new
}
```

- `Version::housingFeePayable()`: per the source doc, `version_fees.housing > 0 and versions.status = closed` — same shape/timing as `participationFeePayable()` (`app/Models/Version.php:171`), not `registrationFeePayable()`'s "any time before closed" timing.
- `VersionFee::amountForCheckout()` (`app/Models/VersionFee.php:42`): add the `Housing => $this->housing` arm to the `match`.
- Every place that currently matches on `FeeType::Registration`/`FeeType::Participation` exhaustively (`VersionDashboard`, `CandidateDetail`, Payment Reconciliation report, `PaymentAllocationService`) needs the third arm added — PHPStan's exhaustive-match checking will surface every call site that needs updating.
- Balance-owed formula (`epayment-integration.md` §5 item 1, confirmed `registration + participation`, no housing) is a **manual/off-platform** balance concept scoped to the school-level Reconciliation report — confirm with the product owner whether adding real Housing checkout changes that confirmed formula, or whether Housing genuinely stays a separate, independently-tracked fee never rolled into "balance owed." **Flagged, not assumed** — do not silently fold Housing into the existing balance formula without confirming.

### 2.2 No new table for "school join," reuses existing pivots

A student's self-service school/teacher join writes the exact same rows a teacher's `Students/Index.php` add-student flow writes (`app/Livewire/Students/Index.php:1080` on, `SchoolStudent::create()` + `StudentTeacher::create()` per selected teacher/subject). No new schema.

---

## 3. Auth: password-reset advisory for school-issued emails

**Gap found, not previously built**: `app/Livewire/Auth/ForgotPassword.php` calls `Password::sendResetLink()` unconditionally with no advisory of any kind. The source doc requires:

> "If the student uses a non-commercial email address, the system should show an advisory: 'Your email address appears to be a school email address which typically DOES NOT permit password reset emails to be received. If you do not get this password reset email, please see your teacher who will be able to reset your password.'"

**Implementation**: reuse `App\Support\EmailVerifiabilityChecker::isLikelyUnverifiable()` (`app/Support/EmailVerifiabilityChecker.php`) — the same check already applied at `StudentRegister::register()` time to set `email_unverifiable`. After `sendResetLink()`, if the submitted address matches, show the advisory alongside (not instead of) the normal "check your email" confirmation, since the checker is a heuristic, not a certainty — the reset email may still arrive.

**Assumption flagged**: the source doc frames this as "commercial domain = OK, else advisory" (an allowlist of icloud/hotmail/gmail/aol/yahoo/etc.), while `EmailVerifiabilityChecker` is a denylist of school-ish patterns (`*.k12.*`, `*.student.*`, `*.school.*`, `*sd*`, plus any `studentfolder.info` address). Reusing the existing checker avoids maintaining two divergent "is this a school email" definitions, but means a commercial-looking domain that doesn't match any pattern is treated as fine (correct per source intent) while an unusual school domain that doesn't match the glob patterns won't trigger the advisory (a false negative the existing registration flow already accepts as a known heuristic limitation). Confirm this reuse is acceptable rather than building a second, allowlist-based check.

**Also note**: a teacher-initiated reset for a student ("please see your teacher who will be able to reset your password") has no existing mechanism today — check whether `Founder`/`Impersonate` tooling already covers this (Founder impersonation exists, `app/Livewire/Founder/Impersonate.php`) or whether a teacher needs a direct "reset this student's password" action. **Not scoped in the source doc beyond the advisory text itself** — flag as a likely fast-follow, not building it this phase unless the product owner wants it now.

---

## 4. Student profile — Biography / School / Emergency Contact(s)

### 4.1 Biography (static)

All fields map directly to existing columns — no schema change:

| Source doc field | Existing column |
|---|---|
| First/Middle/Last/Suffix name | `users.first_name/middle_name/last_name/suffix_name` |
| Email address | `users.email` |
| Preferred pronoun | `users.pronoun_id` → `Pronoun` |
| Grade | derived, not stored directly — see §4.2 (grade only makes sense in the context of a specific school's `senior_year`) |
| Preferred voice part | `students.voice_part_id` → `VoicePart` |
| Home Address (5 fields) | `home_addresses` (`HasOne` on `Student`) |
| Birthday | `students.birthday` |
| Shirt Size | `students.shirt_size` (`ShirtSize` enum, `app/Enums/ShirtSize.php`) |
| Height (inches) | `students.height` — confirm current column type (int inches) still matches "select box" UI intent, or whether a free-number input is acceptable; the source doc says "chosen from select box" but the existing `CandidateDetail` teacher-side edit modal should be checked for its current widget choice and mirrored, not redesigned independently |
| Emergency Contact Name/Email/Cell (top-level, singular) | **Ambiguity**: the source doc lists these as Biography-section fields *and* as a whole separate "Emergency Contact(s)" static section (plural, with Name/Relationship/Email/Cell/Work/Home Phone). The existing schema only has the latter (`emergency_contacts`, `HasMany`) — there is no singular "the" emergency contact field on `students`/`users`. Treat the Biography-section mention as the same `emergency_contacts` data (likely the doc's own redundancy, describing the same real-world concept twice — once as a Candidate-requirement summary, once as its own section) rather than building a second, parallel field. **Confirm this reading before building** if it matters for a specific screen layout the product owner has in mind. |

New Livewire component: `App\Livewire\Students\Profile` (or similar — name TBD at implementation time to avoid colliding with `app/Livewire/Students/Index.php`, which is teacher-facing), route under `/sfdi/*`, editable form(s) for all of the above. Reuses the same enum-driven select options (`Pronoun::orderBy('sort_order')`, `VoicePart::ordered()`, `ShirtSize` cases, `Geostate`) `TeacherOnboardingWizard`/`CandidateDetail` already use.

### 4.2 School (static)

- **School select**: existing schools only (§0) — `School::query()` filtered by geostate/zip the same way `SchoolMatcher::candidates()` narrows results, but **search-only, no create action** exposed to the student (contrast with the teacher onboarding wizard, which does allow creating a school).
- **Grade select**: populated from the chosen school's `SchoolGrade` rows (`School::grades()`, `app/Models/School.php:54`) — a school only accepting grades 9-12, for example, shouldn't offer grade 6.
- **School teacher(s) multi-select**: `School::teachers()->wherePivot('is_active', true)->whereNotNull('school_teacher.verified_at')` — the exact two-condition gate from `[[project_school_teacher_gate]]` (do not relax to `is_active` alone).
- **On submit**: `SchoolStudent::create(['student_id' => ..., 'school_id' => ..., 'is_active' => true, 'class_of' => ClassOfCalculator::classOfFromGrade($grade, $school->senior_year)])`, then one `StudentTeacher::create()` per selected teacher (mirrors `Students/Index.php`'s existing add-student shape). **`SchoolStudentObserver`'s single-active-school invariant** (`[[project_school_student_observer_cascade]]`) already handles deactivating any prior school if a student switches — no extra code needed for "One active school per student," it's an existing enforced invariant, not something to reimplement.
- Creating the `student_teacher` row(s) is what triggers `AutoEnrollmentService::enrollNewStudentIntoInvitedActiveVersions()` (§1.1) — Candidate rows appear automatically, no explicit "enroll me" student action needed at all for Versions that are already open and eligible.

### 4.3 Emergency Contact(s) (static)

Direct CRUD against `EmergencyContact` (`app/Models/EmergencyContact.php`) — `HasMany` on `Student`, already supports multiple contacts with Name/Relationship/Email/Cell/Work/Home Phone exactly as specified. Mirror `CandidateDetail`'s existing add/edit modal pattern (`[[feedback_crud_modal_toast]]` — fire a toast on save, per project convention).

---

## 5. Events (dynamic section)

### 5.1 Eligibility surface

A student-facing "My Events" page (`App\Livewire\Registrations\StudentIndex` or similar, name TBD) lists, per the student's *own* linked teachers (via `student_teacher.is_active`), every Version where:
- `versions.status = active`,
- at least one of the student's linked teachers holds a `version_invitations` row,
- the student's `class_of` matches the Version's eligible pool (`event_grades` + `version_class_ofs`, the same two-layer check `EligibilityService::eligibleStudents()` already applies),
- `now()` is within that Version's `version_dates` `Candidate`-type window (inclusive) — **this is a new date-type check that doesn't exist anywhere yet**: today's `VersionInvitationEligibilityService`/`Registrations/Index` bucketing (`event-version-orientation.md` §6.2) keys off the `Teacher`-type window, not `Candidate`. Add a small helper (e.g. `Version::candidateWindowOpen()`) rather than duplicating the inline date-range check across every place it's needed.

In practice this is a new query, not a reuse of `EligibilityService::eligibleStudents()` verbatim (that method is teacher-rostered and doesn't filter by the `Candidate` date window) — but it should be built as a sibling method on the same service (e.g. `EligibilityService::eligibleVersionsForStudent(Student $student)`) so the eligibility rules for "is this student allowed near this Version at all" have one authoritative home, not two independently-drifting implementations.

Per the source doc, a matching student is a **Candidate with status=eligible** — which, per §4.2, already exists by the time this page is viewed (auto-enrollment created it at school-join time, or will the moment their teacher gets invited). This page is a **view** of existing `Candidate` rows, not an enrollment action.

### 5.2 Candidate requirements

Render `HasCandidateChecklist::checklistDefs($version)` (§1.1) filtered to the "Candidate requirements" subset the source doc names (Birthday, Shirt Size, Height, Home Address, Emergency Contact Name/Email/Cell) — these are exactly the Version-config-gated items already in that trait. Each unmet item links to the relevant §4 profile section for the student to fill in directly (writes flow straight through to `students`/`home_addresses`/`emergency_contacts`, same rows §4 already manages — completing your Biography *is* completing this requirement, not a separate data entry).

### 5.3 Registration requirements

Once Candidate requirements are met, render the remaining `checklistDefs()` items plus the actions to complete them:

- **Voice Part**: select box from `Version::availableVoiceParts()` (§1.2's extension of `CandidateDetail`'s existing constraint). Default/derivation: if the student's `students.voice_part_id` is in the available set, preselect it; otherwise preselect the first available part — this is the exact same resolution order `AutoEnrollmentService::resolveVoicePartId()` already uses at auto-enrollment time (`app/Services/AutoEnrollmentService.php:130`), so the student's initial candidate row and this page's default should never disagree. Advisory copy per the source doc: *"You are auditioning as a {voice_parts.name}."*
- **Program Name**: defaults to `NameFormatter::buildDisplayName($user)` — wait, the source doc says defaults to `users.name`; confirm whether "name" means the full display name (`NameFormatter::buildDisplayName`) or a simpler First + Last, matching what `CandidateObserver::assignProgramName()` already does today (`trim($first.' '.$last)`, `app/Observers/CandidateObserver.php`). **Use the existing `CandidateObserver` default as the source of truth rather than introducing a second default computation** — the source doc's phrase "defaults to users.name" almost certainly describes that existing behavior, not a new one.
- **Application** (§5.6 below).
- **File uploads** (remote audition Versions only) — student-facing upload UI against the existing `version_upload_files` slots / `CandidateUploadFile` model (§1.1), teacher still reviews/approves via existing `CandidateDetail` (unchanged).
- **Pitch Files** (§5.5 below) — reference material, not a checklist requirement itself.
- **Payment** (§5.7 below).

### 5.4 Withdrawal

New student-facing "Withdraw" action on the student's per-Candidate page, calling `CandidateService::withdraw()` (§0: direct, no approval). Uses `CandidateStatus::Withdrew` (distinct from `TeacherWithdrawn`, which the observer/obligations-rejection cascade already use for teacher-initiated withdrawals) — the enum already has an unused `Withdrew` case (`app/Enums/CandidateStatus.php:12`) that appears to have been reserved for exactly this.

### 5.5 Pitch Files

New `App\Livewire\Registrations\CandidatePitchFiles` (or fold into the student's per-Version page), same filter/render shape as the teacher-facing `Registrations/PitchFiles` (§1.2), scoped by the authenticated student's own Candidate row for that Version (ownership check: 404/403 if the Version isn't one of theirs).

**Enforce `pitch_file_visibility` in this same pass, on both pages**: `PitchFileVisibility::Both|Candidate` → visible to the student; `Both|Teacher` → visible to the teacher. Today neither page checks this at all (`event-version-orientation.md` §5.5 explicitly flags it as still-unbuilt) — fixing the teacher side in the same change avoids shipping a student page with correct enforcement next to a teacher page that still ignores the setting entirely.

### 5.6 Candidate Application

Per §0's clarifying answer, the student takes over both signature toggles in `EApplication` mode:

- `toggleApplicationCandidateSigned` / `toggleApplicationParentSigned` (self-attested) — reuse the exact columns/observer-driven `recalculateStatus()` path `CandidateDetail` already exercises (`event-version-orientation.md` §5.7).
- **The teacher's existing toggle capability is not removed.** Rationale: a student who hasn't yet joined StudentFolder.info (or whose account predates this module) still needs their teacher to be able to certify on their behalf during the transition period, and nothing in the source doc or the clarifying answer says to take that away. Both roles can toggle the same two timestamps independently; whichever fires last wins, same as any other shared-record edit already possible via `CandidateDetail`'s existing multi-teacher (`Coteacher`) access model (§9 of `event-version-orientation.md` calls this out as an existing accepted limitation for shared rosters, not new to this feature).
- `Pdf`-mode `application_certified_at` stays **teacher-only** — the source doc's own PDF-mode description says the signed physical document is returned to the teacher, who certifies; there's no equivalent "the student attests" action described for that mode, unlike `EApplication`. Do not add a student-facing certify toggle for `Pdf` mode.
- **PDF download link** (both modes, per source doc's "a PDF version is available for convenience" and the plain-PDF-application path): extend `CandidateApplicationPdfController`'s ownership check (§1.2) to also accept the candidate's own student/user id, not just the owning teacher.
- **View modal**: mirror `CandidateDetail`'s existing "View Application" card+modal (`event-version-orientation.md` §5.7, built 2026-08-13) on the student's own page, rendering the same shared `document.blade.php` partial via `CandidateApplicationData::fromCandidate()`.

### 5.7 Payment

Student-facing "Pay Now" per fee type, gated on:
1. `Version::epaymentStudentEnabled()` (Event Manager turned on student e-payment for this Version), **and**
2. That candidate's specific teacher having opted in (`VersionTeacherEpaymentOptIn::where('version_id', ...)->where('teacher_id', $candidate->teacher_id)->value('opted_in')`) — per the source doc verbatim: *"If the candidate's teacher has opted in..., display the appropriate payment form."* Note this checks the **Candidate's own `teacher_id`**, not just "any teacher at the school" — a candidate's specific enrolling teacher is the one whose opt-in choice governs their fee, matching `epayment-integration.md`'s framing ("individual teachers still decide whether *their* students get to use it").
3. The relevant fee-timing gate is true: `registrationFeePayable()` / `participationFeePayable()` / new `housingFeePayable()` (§2.1) per fee type, matching the source doc's three conditions exactly (registration: fee > 0 and Version active and before the Adjudication-type `version_dates` start; participation/housing: fee > 0 and Version closed).

Checkout flow: reuse `PaymentGatewayFactory::make($version)->createCheckoutSession($version, collect([$candidate]), ...)` exactly as `CandidateDetail`'s teacher-initiated single-candidate Pay Now already does (`epayment-integration.md` §4 step 5) — **the `payer` argument type may need loosening from `Teacher` to accept a `Student`/`Candidate` payer**, since `PaymentGatewayContract::createCheckoutSession()` (`app/Services/Payments/PaymentGatewayContract.php`) is currently typed for a `Teacher` payer only (`payer_teacher_id` on `payment_transactions`) — check whether `payment_transactions.payer_student_id` (already a column per `epayment-integration.md` §1.1's schema table: `"nullable, unused until StudentFolder.info"`) needs the contract/DTO updated to populate it for a student-initiated payment, rather than smuggling the student payment through the teacher-payer field.

**Registration fee condition's exact wording deserves a second look before building**: the source doc says `version_dates.date_type=adjudication and version_dates:start_at > current date` — i.e., registration fee is payable *only before* Adjudication starts, which is a **tighter** window than the existing `registrationFeePayable()` (`app/Models/Version.php:161`, currently just "any time the Version isn't Closed"). Confirm with the product owner whether this is a genuinely new, tighter constraint specific to the *student-initiated* registration payment (candidates can pay any time per the existing teacher-facing rule, but self-service registration payment cuts off at Adjudication) or whether it's meant to describe the same existing rule less precisely. **Flagged, not assumed** — do not silently narrow `registrationFeePayable()` for everyone without confirming this doesn't break the teacher-facing Pay Now / group-payment flow's existing behavior.

---

## 6. Housing FeeType — build checklist

1. `app/Enums/FeeType.php`: add `Housing = 'housing'` + label.
2. `app/Models/Version.php`: add `housingFeePayable()` mirroring `participationFeePayable()`'s shape (`status === Closed`).
3. `app/Models/VersionFee.php`: `amountForCheckout()` match gains `FeeType::Housing => $this->housing`.
4. Run PHPStan after step 1 — Larastan's exhaustive-match checking on `FeeType` will surface every `match`/`match`-like `switch` that needs the new arm (expect hits in `VersionDashboard`, `CandidateDetail`, the Payment Reconciliation report, `PaymentAllocationService`). Treat each PHPStan hit as a required edit, not a false positive.
5. Confirm the balance-owed formula question (§2.1) with the product owner before wiring Housing into any reporting screen's totals.

---

## 7. Authorization

- Every new student-facing Livewire component scopes strictly to `Auth::user()->student` (mirroring `Auth::user()->teacher` throughout the existing Registrations module) and, for per-Candidate pages, additionally verifies `candidate->student_id === $student->id` (404/403 otherwise) — there is no "co-student" analog to a `Coteacher`'s shared-roster access, so this is a straight ownership check, simpler than the teacher-side equivalent.
- No new Spatie role is needed — `Student` role already exists and is assigned at `StudentRegister::register()` time (`app/Livewire/Auth/StudentRegister.php:79`).
- Middleware: confirm whether an `EnsureStudentOnboardingComplete`-style gate (parallel to `EnsureTeacherOnboardingComplete`, `[[project_status]]`) is wanted to force a newly-registered student through the §4.2 school/teacher join before reaching the dashboard, or whether an incomplete profile is simply reflected as "no Events yet" with a visible prompt. **Not decided in the clarifying pass — recommend asking before starting §4.2's build**, since it changes whether §4.2 is a mandatory wizard step or an optional profile-page action.

---

## 8. Proposed phased build order

1. **Profile foundation** (§4.1, §4.3): Biography + Emergency Contact(s) self-service pages. No dependency on anything else; immediately useful even before a student has joined a school.
2. **School/teacher join** (§4.2): the gating decision from §7 should be resolved before this step. Verify `AutoEnrollmentService` really does fire end-to-end from a student-initiated `StudentTeacher::create()` (it's already wired for teacher-initiated creates — confirm no teacher-only assumption snuck into the observer chain).
3. **Events list + Candidate-requirements view** (§5.1, §5.2): read-only at first — surfaces the auto-enrolled Candidate rows and what's missing, before building any write actions.
4. **Registration requirements write actions** (§5.3): voice part, program name, withdrawal (§5.4) — the more mechanical extensions of existing `CandidateDetail` logic.
5. **Pitch Files** (§5.5), fixing `pitch_file_visibility` enforcement on both the new student page and the existing teacher page in the same change.
6. **Candidate Application** (§5.6) — the self-attestation toggles + PDF ownership extension.
7. **Housing FeeType** (§6) — can happen in parallel with 1-6 since it's payment-system plumbing, but must land before step 8.
8. **Payment** (§5.7) — the most architecturally novel piece (payer-type question flagged above); build last so the `payment_transactions.payer_student_id` question is resolved with real student/candidate data already in the system to test against.
9. **Auth advisory** (§3) — small, independent, can happen any time; suggest doing it early since it's low-risk and immediately useful.
10. Regression pass: full Pest suite, PHPStan, manual walkthrough of the whole student journey (register → join school → see an eligible Version → complete requirements → pay → see Registered status) mirrored against the equivalent teacher-side journey to confirm nothing teacher-facing regressed (especially the `FeeType` exhaustive-match changes and the `pitch_file_visibility` fix, both of which touch shared/teacher-facing code).

---

## 9. Open questions / assumptions to confirm before or during implementation

Flagged throughout above, consolidated here:

1. **Housing fee and the confirmed "balance owed = registration + participation" formula** (§2.1, §6 step 5) — does adding real Housing checkout change that confirmed reconciliation math, or does Housing stay outside it?
2. **Registration fee's tighter student-facing window** (§5.7) — `version_dates.date_type=adjudication, start_at > now()` reads as a new, tighter constraint than the existing `registrationFeePayable()`. Confirm before narrowing shared logic.
3. **Payer type on `PaymentGatewayContract`/`payment_transactions`** (§5.7) — does a student-initiated checkout populate `payer_student_id` (already a column, unused) via a widened contract, or is there a reason to keep routing student payments through the teacher-payer shape?
4. **Height/Shirt Size widget** (§4.1) — "select box" per the source doc; confirm against `CandidateDetail`'s current teacher-side widget choice for these two fields before building a second, possibly-inconsistent UI.
5. **Biography-section singular emergency-contact fields vs. the plural Emergency Contact(s) section** (§4.1) — likely the same underlying data described twice; confirm reading.
6. **Mandatory school/teacher join vs. optional profile action** (§7) — affects whether §4.2 is a forced first-run wizard step (`EnsureStudentOnboardingComplete`-style) or a page a student can defer.
7. **Teacher-initiated password reset for students** (§3) — the source doc's advisory text promises "see your teacher who will be able to reset your password," but no such teacher-facing action currently exists. Confirm whether this phase needs to build it or whether Founder impersonation already covers the real-world need.

---

## 10. Test plan outline

Mirror this project's existing coverage conventions (Pest feature tests per Livewire component/service, PHPStan clean, Pint clean) for every item in §8's build order:
- `StudentSchoolJoinTest` — direct join creates correct pivots, respects the active+verified gate, triggers auto-enrollment, respects the single-active-school invariant.
- `StudentBiographyTest` / `StudentEmergencyContactTest` — CRUD, validation parity with the teacher-side equivalents.
- `StudentEventsIndexTest` — eligibility bucketing including the new `Candidate`-type date-window check.
- `StudentCandidateDetailTest` (or equivalent name) — voice part constrained to `availableVoiceParts()`, program name default/edit, withdrawal, application self-attestation toggles, ownership checks (403/404 for another student's candidate).
- `StudentPitchFilesTest` + a regression addition to the existing teacher `PitchFilesTest` for the new `pitch_file_visibility` enforcement.
- `StudentApplicationPdfTest` — extends the existing `CandidateApplicationPdfTest` ownership matrix with a student-owner case.
- `HousingFeeTypeTest` — checkout amount, `housingFeePayable()` timing, and a regression pass across every existing `FeeType`-exhaustive test file touched by the PHPStan-driven edits in §6 step 4.
- `ForgotPasswordAdvisoryTest` — advisory shown for a school-pattern email, not shown for a commercial one, reset link still sent either way.
