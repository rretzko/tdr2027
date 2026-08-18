# StudentFolder.info Module — Implementation Plan

Scoped 2026-08-17 from `StudentFolder Module.docx` (source: `C:\Users\RickRetzko\Documents\products\tdr2027\docs\StudentFolder Module.docx`) plus a clarifying-question pass with the product owner (§0 below). This is the design doc for StudentFolder.info, following this project's usual "design doc → implementation" split (see `event-version-orientation.md` / `epayment-integration.md` for precedent). **Build-order steps 1–7 shipped 2026-08-17–18** — see §8 for the full per-step build log (Profile foundation, School/teacher join, Events list, Registration requirements/Recordings, Pitch Files, Candidate Application, Housing `FeeType`). **Step 8 (§5.7 Payment) is next**, picked up in a following session — it's the most architecturally novel piece (the `payment_transactions.payer_student_id`/payer-type question in §9 item 3 is still open) and was deliberately sequenced last so it has real student/candidate data already in the system to test against. Steps 9 (auth advisory, §3) and 10 (regression pass) remain after that.

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

**Second clarifying-question pass, 2026-08-18** — scoping exactly which Version-scoped properties a candidate may edit from `/sfdi/*`, and the two gates that apply uniformly across all of them:

| Question | Answer |
|---|---|
| Once a candidate is past active registration (`Withdrew`/`TeacherWithdrawn`/`Adjudicated`/`Accepted`/`NotAccepted`/etc.), can the student still edit voice part, application signatures, or recordings? | **No — locked.** Read-only once `candidates.status` leaves `CandidateStatus::registrationStates()` (`eligible`/`pending`/`registered`) — editing a voice part or swapping a recording after adjudication/withdrawal doesn't make sense. The teacher's own `CandidateDetail` is **unaffected** (stays editable at any status, unchanged) — this is a student-only restriction, not a new rule for teachers. |
| Does a student's access to these same pages depend on their specific teacher's Version Obligations decision? | **Yes — inherits the iron gate.** If the candidate's teacher has rejected this Version's obligations, the student's own voice part/application/recordings pages redirect the same way `GuardsAcceptedObligations` already blocks the teacher (`event-version-orientation.md` §5.6/§6.2) — reuse that trait's `redirectUnlessObligationsAccepted()`, scoped to the candidate's own `teacher_id`. |
| For Recordings, can a student delete a Pending (not-yet-approved) upload without immediately re-uploading a replacement? | **Yes.** Mirrors the teacher's own `rejectRecording()` (deletes the file/row outright and reopens the slot) — the student isn't forced to have a replacement ready in the same action. Once a recording is **Approved**, the student may only replay it — upload/delete/re-upload are unavailable until the teacher un-approves it (no student-facing "undo an approval" action; that stays `rejectRecording()`, teacher-only). |
| Is `pitch_file_visibility` enforcement scoped to the new student page only, or does it also get retrofitted onto the existing teacher-facing `Registrations\PitchFiles` page? | **Both, in this pass.** That teacher page (built 2026-08-17, `event-version-orientation.md` §5.5) currently shows every pitch file regardless of the setting — fixing it there too avoids shipping a correct student page next to a teacher page that still ignores the setting, matching the intent already flagged when that page was first built (`event-version-orientation.md` §9 item 11). |

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
| `CandidateDetail`'s Recordings section (`uploadRecording`/`saveRecording`/`approveRecording`/`rejectRecording`) | same | Student-facing upload/replay/delete/re-upload against the same `version_upload_files` slots / `CandidateUploadFile` rows — **but only while `status = Pending`**. Once the teacher's `approveRecording()` sets `status = Approved`, the student may only replay it (see §0's second pass) — `approveRecording()`/`rejectRecording()` themselves stay teacher-only, unchanged |
| `Registrations/PitchFiles` read-only view | `app/Livewire/Registrations/PitchFiles.php` | New candidate-facing equivalent, additionally enforcing `pitch_file_visibility` (see §5.5 — **not enforced on the teacher-facing page today either**; per §0's second pass, fixing both in the same change) |
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

**Built 2026-08-18 as `App\Livewire\Sfdi\Events\Index`** — simpler than originally sketched here: rather than the `Version::candidateWindowOpen()`/`EligibilityService::eligibleVersionsForStudent()` machinery this section originally proposed, the shipped version is a direct, literal read of the student's own `Candidate` rows (`Candidate::where('student_id', $student->id)->whereHas('version', fn ($q) => $q->where('status', 'active'))`) — since, per the paragraph below, a matching student's `Candidate` row already exists by the time this page is viewed (auto-enrollment already created it), there was nothing left for a separate eligibility re-derivation to add for a **read-only listing**. The `Candidate`-type date-window question below remains relevant for any *future* write-gated use of "is this student allowed near this Version" (e.g. a mandatory-enrollment-window check), but wasn't needed for the list itself.

Original eligibility-window analysis (kept for reference / any future write-gated need): every Version where
- `versions.status = active`,
- at least one of the student's linked teachers holds a `version_invitations` row,
- the student's `class_of` matches the Version's eligible pool (`event_grades` + `version_class_ofs`, the same two-layer check `EligibilityService::eligibleStudents()` already applies),
- `now()` is within that Version's `version_dates` `Candidate`-type window (inclusive) — **a new date-type check that doesn't exist anywhere yet**: today's `VersionInvitationEligibilityService`/`Registrations/Index` bucketing (`event-version-orientation.md` §6.2) keys off the `Teacher`-type window, not `Candidate`. If a future step needs this, add a small helper (e.g. `Version::candidateWindowOpen()`) rather than duplicating the inline date-range check across every place it's needed.

Per the source doc, a matching student is a **Candidate with status=eligible** — which, per §4.2, already exists by the time this page is viewed (auto-enrollment created it at school-join time, or will the moment their teacher gets invited). This page is a **view** of existing `Candidate` rows, not an enrollment action.

### 5.2 Candidate requirements

Render `HasCandidateChecklist::checklistDefs($version)` (§1.1) filtered to the "Candidate requirements" subset the source doc names (Birthday, Shirt Size, Height, Home Address, Emergency Contact Name/Email/Cell) — these are exactly the Version-config-gated items already in that trait. Each unmet item links to the relevant §4 profile section for the student to fill in directly (writes flow straight through to `students`/`home_addresses`/`emergency_contacts`, same rows §4 already manages — completing your Biography *is* completing this requirement, not a separate data entry).

### 5.3 Registration requirements

Once Candidate requirements are met, render the remaining `checklistDefs()` items plus the actions to complete them.

**Two gates apply uniformly to every write action in this section** (voice part, application self-attestation, recordings — confirmed §0, second pass, 2026-08-18):
- **Status lock**: read-only once `candidates.status` leaves `CandidateStatus::registrationStates()` (`eligible`/`pending`/`registered`). Implement as a single `bool $locked = ! in_array($candidate->status, CandidateStatus::registrationStates(), true)` computed once in the page's `mount()`/`render()` and passed to every action's guard — not re-derived per action. The teacher's own `CandidateDetail` is **unaffected**, unchanged at every status.
- **Obligations iron gate**: the *check* is directly reusable — `GuardsAcceptedObligations::redirectUnlessObligationsAccepted()`'s underlying condition (`$version->obligation?->isPublished()` and `$invitation?->obligationResponse?->isAccepted()`, resolved against the candidate's own `teacher_id`'s `version_invitations` row, not `Auth::user()->teacher`) is not teacher-specific. The *redirect target* is, though — `redirectUnlessObligationsAccepted()` sends the caller to `registrations.obligations`, the teacher-only obligations-response page, which would break for a student (no `Auth::user()->teacher` to resolve there). **Do not call the trait's method directly for the student page.** Instead, replicate its boolean condition locally and render an informational block in place of the write actions ("Registration is temporarily paused — your teacher needs to review this Event's requirements before you can continue") rather than redirecting anywhere; the student has no action available to resolve this themselves.

- **Voice Part**: select box from `Version::availableVoiceParts()` (§1.2's extension of `CandidateDetail`'s existing constraint) — this already resolves to exactly "the event's ensemble voice parts" (`ensemble_voice_parts` → `ensembles.event_id`, `app/Models/Version.php:366`), so no new query is needed. Default/derivation: if the student's `students.voice_part_id` is in the available set, preselect it; otherwise preselect the first available part — this is the exact same resolution order `AutoEnrollmentService::resolveVoicePartId()` already uses at auto-enrollment time (`app/Services/AutoEnrollmentService.php:130`), so the student's initial candidate row and this page's default should never disagree. Advisory copy per the source doc: *"You are auditioning as a {voice_parts.name}."* Subject to both gates above.
- **Program Name**: defaults to `NameFormatter::buildDisplayName($user)` — wait, the source doc says defaults to `users.name`; confirm whether "name" means the full display name (`NameFormatter::buildDisplayName`) or a simpler First + Last, matching what `CandidateObserver::assignProgramName()` already does today (`trim($first.' '.$last)`, `app/Observers/CandidateObserver.php`). **Use the existing `CandidateObserver` default as the source of truth rather than introducing a second default computation** — the source doc's phrase "defaults to users.name" almost certainly describes that existing behavior, not a new one.
- **Application** (§5.6 below).
- **Recordings** (§5.3.1 below).
- **Pitch Files** (§5.5 below) — reference material, not a checklist requirement itself, and **not** subject to the two gates above (read-only either way).
- **Payment** (§5.7 below).

#### 5.3.1 Recordings (remote audition Versions only)

Student-facing counterpart to `CandidateDetail`'s existing Recordings section (`event-version-orientation.md` §5.2/§9 item 33), against the same `version_upload_files` slots / `CandidateUploadFile` rows — the student is a second, additional uploader alongside the teacher, not a separate data model. No new schema.

Per slot (`VersionUploadFile`), keyed off `CandidateUploadFile.status`:
- **No upload yet**: Upload action — same validation as the teacher's `saveRecording()` (mime/size rules per `allowedRecordingMimes()`, `RecordingReviewService`'s non-blocking mis-filed-recording assist).
- **Pending** (uploaded, not yet reviewed): Replay (signed `temporaryUrl()`, same S3 pattern the teacher page already uses — private-by-default bucket, `event-version-orientation.md` §9 item 34), **Delete** (removes the file/row outright and reopens the slot — mirrors `rejectRecording()`'s delete-not-a-status behavior; per §0's second pass, the student is not forced to re-upload in the same action), **Re-upload** (replaces in place, same as `saveRecording()`'s "existing" branch — reverts to `Pending`, same as today).
- **Approved**: **Replay only.** Upload/Delete/Re-upload are hidden — a student cannot alter or remove a recording their teacher has already approved, which may already be synced into `recordings` for judge scoring (`approveRecording()`'s `Recording::updateOrCreate()`). There is no student-facing "undo an approval" action; only the teacher's existing `rejectRecording()` can reverse one.

Both §5.3 gates apply (status lock, obligations iron gate) — a locked/gated candidate sees every slot as replay-only regardless of its own `CandidateUploadStatus`, same visual treatment as an Approved slot.

### 5.4 Withdrawal

New student-facing "Withdraw" action on the student's per-Candidate page, calling `CandidateService::withdraw()` (§0: direct, no approval). Uses `CandidateStatus::Withdrew` (distinct from `TeacherWithdrawn`, which the observer/obligations-rejection cascade already use for teacher-initiated withdrawals) — the enum already has an unused `Withdrew` case (`app/Enums/CandidateStatus.php:12`) that appears to have been reserved for exactly this.

### 5.5 Pitch Files

**Built 2026-08-18, as a modal, not a separate page** — a mid-build product-owner correction to this section's original sketch (a standalone `Sfdi\Events\PitchFiles` route/component, built first, then converted same-day). Rationale: the teacher's `Registrations\PitchFiles` stays a full page because it needs a free voice-part filter across the whole roster; a candidate only ever has one voice part per Version, so there's nothing to filter — a `<flux:modal name="pitch-files">` triggered from `Show` (`viewPitchFiles()`, mirroring the existing "View Application"-style trigger-only modals already on that page) keeps the student on the page instead of a full navigation, consistent with every other action on `Show`. The data (`matchingPitchFiles()`, `pitchFilesVisible`) is computed directly in `Show::render()`, no separate Livewire component or route.

**Enforce `pitch_file_visibility` on both pages** (confirmed §0, second pass): `PitchFileVisibility::Both|Candidate` → visible to the student; `Both|Teacher` → visible to the teacher. Neither page checked this before this pass (`event-version-orientation.md` §5.5 had flagged it as still-unbuilt) — fixing the teacher side in the same change avoids a student surface with correct enforcement next to a teacher page that still ignores the setting entirely. This is a real behavior change to the already-built teacher page: a Version set to `Candidate`-only now hides those files from the teacher's own `Registrations\PitchFiles`.

**Voice-part matching, student side**: unlike the teacher page's free voice-part filter dropdown, the modal needs no filter UI — it's automatically scoped to the candidate's own `voice_part_id` plus the seeded `ALL` voice part, reusing the exact same match `Registrations\PitchFiles::filter()` already applies for the "always show regardless of filter" case (`$pitchFile->voicePart->abbr === 'ALL'`): `$pitchFile->voice_part_id === $candidate->voice_part_id || $pitchFile->voicePart->abbr === 'ALL'`. The modal's own heading is `"{voice_parts.name} Pitch Files"` (e.g. "Soprano Pitch Files"), not a generic "Pitch Files" label.

### 5.6 Candidate Application

**Built 2026-08-18.** Per the source rule verbatim: `EApplication`-mode Versions surface the two self-attestation checkboxes as the student's write action; `Pdf`-mode Versions surface a download action instead (there's nothing to self-attest — see the certify note below). The PDF download link and View modal are available in **both** modes regardless, as a read-only convenience (per the source doc's own "a PDF version is available for convenience" framing) — only the two `EApplication` checkboxes are subject to §5.3's gates (status lock, obligations iron gate); the download link and View modal stay available at any status/gate state, same as they're read-only for the teacher today.

Per §0's clarifying answer, the student takes over both signature toggles in `EApplication` mode:

- `toggleApplicationCandidateSigned` / `toggleApplicationParentSigned` (self-attested) — reuse the exact columns/observer-driven `recalculateStatus()` path `CandidateDetail` already exercises (`event-version-orientation.md` §5.7). Both gated by §5.3's status lock and obligations iron gate.
- **The teacher's existing toggle capability is not removed.** Rationale: a student who hasn't yet joined StudentFolder.info (or whose account predates this module) still needs their teacher to be able to certify on their behalf during the transition period, and nothing in the source doc or the clarifying answer says to take that away. Both roles can toggle the same two timestamps independently; whichever fires last wins, same as any other shared-record edit already possible via `CandidateDetail`'s existing multi-teacher (`Coteacher`) access model (§9 of `event-version-orientation.md` calls this out as an existing accepted limitation for shared rosters, not new to this feature).
- `Pdf`-mode `application_certified_at` stays **teacher-only** — the source doc's own PDF-mode description says the signed physical document is returned to the teacher, who certifies; there's no equivalent "the student attests" action described for that mode, unlike `EApplication`. Do not add a student-facing certify toggle for `Pdf` mode.
- **PDF download link** (both modes, per source doc's "a PDF version is available for convenience" and the plain-PDF-application path): extend `CandidateApplicationPdfController`'s ownership check (§1.2) to also accept the candidate's own student/user id, not just the owning teacher.
- **View modal**: mirror `CandidateDetail`'s existing "View Application" card+modal (`event-version-orientation.md` §5.7, built 2026-08-13) on the student's own page, rendering the same shared `document.blade.php` partial via `CandidateApplicationData::fromCandidate()`.

**Two additions beyond this section's original scope, both product-owner requests made while reviewing the built feature:**
- **Simulated signature rendering** — once `application_candidate_signed_at`/`application_parent_signed_at` is set, the shared `document.blade.php` partial (View modal, both roles, *and* the PDF download — one render path) now shows the signer's name in a cursive `.ca-signature` style plus the real signed date in place of the blank `___` line, with a small "Electronically signed {timestamp}" caption; the parent line's caption clarifies it was signed "by the candidate, on the parent/guardian's behalf" (no separate parent account exists — §0). Unsigned stays a blank line, exactly as before. Both `Show::applicationDocumentView()` and `CandidateDetail::applicationDocumentView()` now pass `candidateSignedAt`/`parentSignedAt` (via `Carbon::make()` — the model's `casts()` return confuses Larastan's inference otherwise, see `feedback_phpstan_quirks`) into the partial; `CandidateApplicationPdfController` and the `VersionEdit`/admin Preview modal pass the same two keys (`null` for the admin preview, which has no real candidate).
- **Dark-mode paper background** — `document.blade.php`'s root element now sets an explicit `background-color: #ffffff; color: #000000;` rather than inheriting the app's theme, since it represents an actual paper/PDF document that should look like paper regardless of light/dark mode (found via a real dark-mode screenshot: its hardcoded `lightblue`/`lightgray` section-header backgrounds had unreadable contrast against inherited dark-mode white text). Applied for consistency to every other in-app "View a document" surface too: the Obligations Preview modal (`version-edit.blade.php`, admin authoring) and the teacher-facing Obligations accept/reject page (`version-obligations.blade.php`) — both switched from theme-adaptive `dark:` classes to the same explicit white/black treatment. Estimate Form and the score-report modals were checked and don't need it (download-only, or already plain Tailwind with no hardcoded colors).

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
- Middleware: **resolved — built 2026-08-17.** `App\Http\Middleware\EnsureStudentHasActiveSchool` (alias `student.has.active.school`) redirects to `/sfdi/school` whenever `Auth::user()->student->current_school === null`, applied to the `/dashboard` route. §4.2 (School join) is a mandatory first-run destination, not an optional profile action. The new §5.1–§5.3 pages (`/sfdi/events`, `/sfdi/events/{candidate}`) are gated behind the same middleware (`routes/web.php`), since they depend on having an active school/teacher link to have auto-enrolled any `Candidate` rows in the first place.

---

## 8. Proposed phased build order

1. ~~**Profile foundation** (§4.1, §4.3): Biography + Emergency Contact(s) self-service pages.~~ **Built 2026-08-17** — `App\Livewire\Sfdi\StudentDetails`/`EmergencyContacts`.
2. ~~**School/teacher join** (§4.2)~~ **Built 2026-08-17** — `App\Livewire\Sfdi\School`; `AutoEnrollmentService` confirmed firing end-to-end from a student-initiated join.
3. ~~**Events list + Candidate-requirements view** (§5.1, §5.2): read-only.~~ **Built 2026-08-18** — `App\Livewire\Sfdi\Events\Index`/`Show`.
4. ~~**Registration requirements write actions** (§5.3): voice part, program name, withdrawal (§5.4), Recordings upload/replay/delete/re-upload with the approved-locks-to-replay-only rule (§5.3.1).~~ **Built 2026-08-18** — all folded into `App\Livewire\Sfdi\Events\Show` (no separate page): `editVoicePart`/`saveVoicePart`, `editProgramName`/`saveProgramName`, `withdraw` (new `CandidateService::withdrawByCandidate()`, `CandidateStatus::Withdrew`), and `uploadRecording`/`saveRecording`/`deleteRecording` with the per-slot Approved-locks-to-replay-only check (`recordingLocked()`). Both new gates (`isLocked()`/`isObligationsBlocked()`) implemented as private guards on every action plus a `$writeActionsBlocked` flag the view uses to swap in an informational callout. 16 new tests in `ShowTest.php`, PHPStan/Pint clean, 25/25 pre-existing Sfdi tests still green.
5. ~~**Pitch Files** (§5.5), fixing `pitch_file_visibility` enforcement on both the new student page and the existing teacher page in the same change.~~ **Built 2026-08-18** — as a `Show`-hosted modal, not a separate page (see §5.5's note); `Registrations\PitchFiles` retrofitted with the same visibility gate. 36 tests across both, PHPStan/Pint clean.
6. ~~**Candidate Application** (§5.6) — the self-attestation toggles (subject to the same two gates) + PDF ownership extension (not gated — read-only).~~ **Built 2026-08-18** — `viewApplication()`/`toggleApplicationCandidateSigned()`/`toggleApplicationParentSigned()` on `Show`, `CandidateApplicationPdfController`'s ownership check widened to accept the owning student, plus two out-of-scope-but-requested additions: simulated signature rendering in the shared document partial, and a dark-mode paper-background fix applied to every document "View" surface app-wide (see §5.6's own note for both). 28 tests in `ShowTest.php`, PHPStan/Pint clean.
7. ~~**Housing FeeType** (§6)~~ **Built 2026-08-18** — `FeeType::Housing`, `Version::housingFeePayable()` (mirrors `participationFeePayable()`'s timing exactly), `VersionFee::amountForCheckout()`/`housingInDollars()`. Two product-owner decisions resolved first: (1) the confirmed "balance owed = registration + participation" formula (`epayment-integration.md` §5) stays unchanged — Housing is tracked as its own independent due/paid pair (matched via `payment_transactions.fee_type`), never folded into `PaymentReconciliation`/`ParticipatingSchools`' existing totals; (2) since housing and participation share identical timing (`status === Closed`), both can be simultaneously chargeable — `CandidateDetail`'s per-candidate Pay Now gained a third independent button (`@if`, not `@elseif`), and `VersionDashboard`'s roster-wide group-pay button became `$activeFeeTypes` (plural, 0–2 entries) with one button per active type. PHPStan's exhaustive-match check (`vendor/bin/phpstan analyse`, run immediately after adding the enum case per this section's own instruction) found exactly the 4 call sites expected — no surprises. 14 new tests across `CandidateDetailTest` (+4), `VersionDashboardTest` (+3), and a new `HousingFeeTypeTest` (7); full existing suites in both files (67 and 42 respectively) re-run green, plus `PaymentReconciliationTest`/`ParticipatingSchoolsTest` (26) to confirm the balance formula is untouched. PHPStan/Pint clean app-wide.
8. **Payment** (§5.7) — the most architecturally novel piece (payer-type question flagged above); build last so the `payment_transactions.payer_student_id` question is resolved with real student/candidate data already in the system to test against.
9. **Auth advisory** (§3) — small, independent, can happen any time; suggest doing it early since it's low-risk and immediately useful.
10. Regression pass: full Pest suite, PHPStan, manual walkthrough of the whole student journey (register → join school → see an eligible Version → complete requirements → pay → see Registered status) mirrored against the equivalent teacher-side journey to confirm nothing teacher-facing regressed (especially the `FeeType` exhaustive-match changes and the `pitch_file_visibility` fix, both of which touch shared/teacher-facing code).

---

## 9. Open questions / assumptions to confirm before or during implementation

Flagged throughout above, consolidated here:

1. **Housing fee and the confirmed "balance owed = registration + participation" formula** (§2.1, §6 step 5) — does adding real Housing checkout change that confirmed reconciliation math, or does Housing stay outside it?
2. **Registration fee's tighter student-facing window** (§5.7) — `version_dates.date_type=adjudication, start_at > now()` reads as a new, tighter constraint than the existing `registrationFeePayable()`. Confirm before narrowing shared logic.
3. **Payer type on `PaymentGatewayContract`/`payment_transactions`** (§5.7) — does a student-initiated checkout populate `payer_student_id` (already a column, unused) via a widened contract, or is there a reason to keep routing student payments through the teacher-payer shape?
4. ~~**Height/Shirt Size widget** (§4.1)~~ — **Resolved, built 2026-08-17**: `StudentDetails` deliberately matches `CandidateDetail`'s existing teacher-side widgets (height as a number input, geo_state as a free 2-char input) rather than the source doc's literal "select box."
5. ~~**Biography-section singular emergency-contact fields vs. the plural Emergency Contact(s) section** (§4.1)~~ — **Resolved, built 2026-08-17**: no separate singular field was built; `EmergencyContacts` (plural) is the only emergency-contact surface, confirming the "same data described twice" reading.
6. ~~**Mandatory school/teacher join vs. optional profile action** (§7)~~ — **Resolved, built 2026-08-17**: `EnsureStudentHasActiveSchool` middleware forces the school join before `/dashboard` (and now `/sfdi/events`) is reachable.
7. **Teacher-initiated password reset for students** (§3) — the source doc's advisory text promises "see your teacher who will be able to reset your password," but no such teacher-facing action currently exists. Confirm whether this phase needs to build it or whether Founder impersonation already covers the real-world need.
8. ~~**`GuardsAcceptedObligations`'s redirect target is teacher-only** (§5.3, §0 second pass)~~ — **Resolved, built 2026-08-18**: `Show::isObligationsBlocked()` replicates the trait's boolean condition locally and renders an informational callout in place of the write actions instead of redirecting; shipped as part of step 4, no further confirmation needed.

---

## 10. Test plan outline

Mirror this project's existing coverage conventions (Pest feature tests per Livewire component/service, PHPStan clean, Pint clean) for every item in §8's build order. Items already built (steps 1–3) have their coverage in `tests/Feature/Livewire/Sfdi/*` and `tests/Feature/Livewire/Sfdi/Events/*` already — not repeated here.

Step 4 (`tests/Feature/Livewire/Sfdi/Events/ShowTest.php`, extending the existing file):
- Voice part constrained to `availableVoiceParts()`; program name default/edit; withdrawal.
- **Status lock**: for each of `Withdrew`/`TeacherWithdrawn`/`Adjudicated`/`Accepted`/`NotAccepted`, confirm voice part/application/recording write actions are rejected (403 or a no-op + validation error, whichever the implementation lands on) while the teacher's own `CandidateDetail` equivalent edit still succeeds unchanged — a direct regression guard against the two diverging.
- **Obligations iron gate**: a candidate whose teacher rejected obligations sees the read-only informational block, not the write actions; re-accepting restores them. Mirror `VersionDashboardTest`'s existing "redirect-when-eligible-uninvited"-style setup for the obligations fixture.
- Ownership checks (404 for another student's candidate) — already covered for the read-only page in the existing `ShowTest.php`; extend to every new write action.

Step 4 Recordings (new `tests/Feature/Livewire/Sfdi/Events/RecordingsTest.php`, or folded into `ShowTest.php`):
- Upload into an empty slot; replay a Pending upload (signed URL present); delete a Pending upload leaves the slot empty (no forced replacement); re-upload replaces in place and reverts to Pending, mirroring `saveRecording()`'s existing-branch behavior.
- **Approved-locks-to-replay-only**: once `CandidateUploadFile.status = Approved` (via the teacher's `approveRecording()`), the student's upload/delete/re-upload actions are rejected (403) — only replay remains available. Confirm the synced `recordings` row is untouched by the (rejected) student action.
- Both gates from the general Step 4 list apply here too — a locked/gated candidate can't upload/delete/re-upload even on a Pending slot.

Step 5 (new `tests/Feature/Livewire/Sfdi/Events/PitchFilesTest.php` + a regression addition to the existing `tests/Feature/Livewire/Registrations/PitchFilesTest.php`):
- Student page: a `Both`/`Candidate` Version shows files matching the candidate's own `voice_part_id` plus `ALL`; a `Teacher`-only Version shows nothing.
- Teacher page regression: a `Candidate`-only Version now hides those pitch files from the teacher's existing `Registrations\PitchFiles` (previously showed everything unconditionally) — lock in the intentional behavior change called out in §5.5.

Step 6 — **built**, `ShowTest.php` + `tests/Feature/CandidateApplicationPdfTest.php`:
- Self-attestation toggles subject to both gates (status lock, obligations); PDF download link and View modal remain reachable regardless of gate state (read-only exemption, §5.6).
- `CandidateApplicationPdfTest` — extended the existing ownership matrix with owning-student and non-owning-student cases.
- Signature rendering: unsigned shows the blank line (no "Electronically signed" text); once toggled, the document shows the `.ca-signature` styling and the real signed date — regression-guards the reordering fix (a stale in-memory `$candidate` passed into a second `Livewire::test()` call after the first mutated the DB) found while writing this test.

Remaining steps:
- `HousingFeeTypeTest` — checkout amount, `housingFeePayable()` timing, and a regression pass across every existing `FeeType`-exhaustive test file touched by the PHPStan-driven edits in §6 step 4.
- `ForgotPasswordAdvisoryTest` — advisory shown for a school-pattern email, not shown for a commercial one, reset link still sent either way.
