# Plan: preventing duplicate student records

## Problem

Nothing today stops two different teachers — same school, different school, or a
studio — from independently creating two separate `User`/`Student` records for the
same real kid. `saveAdd()` in `App\Livewire\Students\Index` always does an
unconditional `User::create()` + `Student::create()`.

Worse, the existing email-collision handling actively *masks* duplicates rather
than catching them: if a studio teacher types a student's real email and it's
already taken (because their school teacher already has them on file), `saveAdd()`
silently assigns a synthetic `@studentfolder.info` address and creates a second,
unrelated `Student` row for the same human.

This isn't a studio-specific problem — it's "any Add-student flow has zero
identity matching." Fix it generally, then make it stricter specifically for
studios, since studios are statistically far more likely to be re-adding a kid
who already has a school record in the system.

## Guiding principles

1. Fix duplicate creation once, generally — not as two separate systems for
   schools vs. studios.
2. Reuse the trust boundary the app already has: a teacher with a verified
   `school_email` at School X is already trusted with that school's whole
   roster (see `App\Support\ReplacedTeacherStudentTransfer` — verified-email
   teachers get existing students' rows handed to them automatically, no extra
   confirmation). Matches **within the same school** auto-attach; matches
   **across schools/studios** require a confirmation handshake.
3. Don't leak PII while matching. A suggestion can only resurface information
   the requesting teacher already typed (name, birthday) plus which
   school/studio the match is currently enrolled at. Emergency contacts,
   address, email, and phone stay hidden until a cross-org claim is approved.

## 1. `StudentMatcher` (new, sibling to `App\Support\SchoolMatcher`)

Searches all students system-wide (no org scoping — the point is catching
cross-org dupes) and scores candidates:

| Signal | Tier |
|---|---|
| Exact birthday + strong first/last name similarity | **Strong** |
| Exact match on an emergency contact's email/phone vs. what's being entered for the new student | **Strong** |
| Name similarity only, no birthday (birthday is optional today on `edit_birthday`) | **Weak** |

Strong matches are surfaced to the requesting teacher as: *name, current
school/studio name, grade*. Nothing else — no address/contact info before
approval.

## 2. Resolution paths once a match is found

- **Same school as an existing claim** (the requester already has an active
  `school_teacher` row at that school): auto-attach. Create a new
  `student_teacher` row pointing at the *existing* `Student`, immediately
  active — no new `User`/`Student` created, no approval needed. Mirrors the
  trust level `ReplacedTeacherStudentTransfer` already grants verified
  same-school teachers.
- **Different school/studio than any existing claim**: don't attach yet.
  Create the new `student_teacher` row in a pending state, email the existing
  teacher(s) of record an approve/deny link (same shape as
  `App\Mail\SchoolEmailVerificationMail`'s signed URL), and show the requester
  a "Pending approval from {School}" badge — reuse the pending-badge pattern
  already on the Schools index.
- **No match at all**: today's behavior, unchanged — create a new
  `User`/`Student`.
- **Matched student has zero currently-active teachers anywhere** (everyone
  who once taught them has gone inactive/left): **auto-attach** — decided.
  Nobody is positioned to gatekeep, so let the new teacher attach directly;
  flag it for later visibility/audit rather than blocking on it.

## 3. Strictness: dismissible vs. mandatory

- **Strong match** → mandatory everywhere (school or studio). The teacher must
  explicitly pick "request to add as my student" or "this is a different
  person" (logged) before saving — never silently bypassed.
- **Weak match, school context** → dismissible suggestion, same as Schools'
  "none of these, add new anyway" today. Common-name false positives shouldn't
  block a school teacher adding a genuinely new freshman.
- **Weak match, studio context** → each suggestion must be explicitly
  dismissed before "add new anyway" appears. Not a hard block (a studio-only
  kid with no school in the system must still be addable), but studios don't
  get the one-click bypass schools get.

## 4. Data model changes

- `student_teacher`: add a `claim_status` column (`approved` default /
  `pending`), distinct from `is_active` (which already means "currently
  active," not "awaiting approval"). A pending row is also `is_active = false`
  until approved.
- New `App\Mail\StudentClaimMail` (mirrors `SchoolEmailVerificationMail`) + a
  signed-route `student-claim.approve` / `student-claim.deny` pair of
  endpoints.
- New flag or audit log entry when a "different person, create new anyway"
  override happens on a *strong* match — cheap insurance for later cleanup,
  not a blocker.

## 5. Interaction with the `home_school_id` field already built

Once matching ships, `home_school_id` (added on `Student` for the
studio/"Student's School" feature) becomes redundant for any
**matched-and-claimed** student — their real school is already a normal
`school_student` row, discoverable the regular way. `home_school_id` still
earns its keep for the **unmatched** case: a studio-only student whose actual
school isn't a TDR customer at all, so there's nothing to match against.

Net effect: the eventual event-eligibility-check feature needs to check the
real `school_student`/`student_teacher` rows first and fall back to
`home_school_id` only when no such row exists.

## 6. Backstop: admin merge tool

A simple, not-teacher-facing tool (console command or hidden admin route) to
merge two `Student` ids — reassigns `student_teacher` / `school_student` /
`emergency_contacts` / `home_address` from one to the other, deletes the
loser. Phase 3 — useful insurance, doesn't block shipping the rest.

## Phasing

1. **Phase 1** — `StudentMatcher` + suggestion UI in the Add-student modal +
   same-school auto-attach. Fixes the most common case (a co-teacher at the
   same school re-adding a kid) with no new infra (no emails, no pending
   state). **Done — see "Phase 1: what shipped" below.**
2. **Phase 2** — cross-org pending claim + approval email. This is the one
   that actually addresses the studio scenario that started this
   conversation. **Done — see "Phase 2: what shipped" below.**
3. **Phase 3** — admin merge tool, as a backstop. **Done — see "Phase 3: what shipped" below.**

## Phase 1: what shipped (2026-06-25)

- `App\Support\StudentMatcher::suggestions()` — scores candidates by name
  similarity (`similar_text`, same approach as `SchoolMatcher`) and promotes a
  match to "strong" when the birthday also matches, or to "strong"
  unconditionally on an exact emergency-contact email/phone match.
- `App\Livewire\Students\Index`: a live (debounced) suggestion list appears
  under the name fields in the Add-student modal once first+last name are
  typed (`studentMatcherMatches()` / `unresolvedStudentMatches()`). Each
  suggestion can be dismissed (`dismissStudentMatch()`) or, when the matched
  student already has an active enrollment at the school/studio being added
  to (`studentMatchIsSameSchool()`), attached instead
  (`selectStudentMatch()` → `attachExistingStudent()`).
- Attaching switches the modal into a compact "claim this existing student"
  mode (read-only student/school/grade display + Subject/Role only) instead
  of the full profile form — mirrors the `linkingSchoolId` pattern already
  used by the Schools index for linking to a suggested existing school.
  Creates only a new `student_teacher` row; the existing `Student`'s profile,
  contacts, and address are left untouched.
- `saveAdd()` is blocked (`blockingStudentMatches()`) until every strong match
  is resolved (school or studio context), and — in a studio context only —
  every weak match too. A cross-org match (different school than the one
  being added to) currently offers no attach action, only dismiss, since the
  claim/approval workflow (Phase 2) doesn't exist yet — this is a known,
  intentional limitation of Phase 1, not a bug.
- Tests: `tests/Feature/Livewire/Students/IndexTest.php` covers weak-vs-strong
  blocking by context, same-school attach (no duplicate `User` created),
  cross-school match offering dismiss-only, and subject/role validation on
  attach.
- Found and fixed along the way: the locally-seeded legacy dataset
  (`database/seeders/data/students.csv`, gitignored, loaded via `$seed = true`
  in `TestCase`) contains a real "Test Student" record that fuzzy-matched
  generic test fixture names — confirms the matcher works against real data,
  but means new tests touching `saveAdd`/`saveEdit` should use distinctive
  fixture names (not "New"/"Student" or similar generic placeholders) to stay
  deterministic regardless of what's in the local seed data.
- **Production bug found and fixed same day**: a real teacher hit a 500 error
  (`student_teacher`'s unique constraint violated) adding a student who turned
  out to already be on their own roster at that school under the same
  subject — the suggestion list had no reason to hide that match (it wasn't
  excluding "students I already claim here"), and `attachExistingStudent()`
  inserted unconditionally instead of checking first. Fixed by (1) excluding
  any candidate the requesting teacher already has *any* `student_teacher`
  row for at that school from the suggestion list at all
  (`teacherAlreadyHasStudentAtAddSchool()`), and (2) hardening
  `attachExistingStudent()` itself to check per-subject before inserting —
  reactivating a deactivated claim, skipping an already-active one, and only
  creating genuinely new subject rows — so the same situation can never crash
  even if reached another way. Regression tests added.

## Phase 2: what shipped (2026-06-25)

- `App\Enums\ClaimStatus` — new enum (`approved` / `pending`).
- Migration `add_claim_status_to_student_teacher_table` — adds `claim_status`
  string (default `'approved'`) and `pending_class_of` nullable
  `unsignedSmallInteger` to `student_teacher`.
- `App\Models\Pivots\StudentTeacher` — `isPending()` method, `ClaimStatus`
  cast, `claim_status` / `pending_class_of` added to `$fillable`.
- `App\Mail\StudentClaimMail` + `resources/views/mail/student-claim.blade.php`
  — approval-request email sent to each current active teacher; includes
  Approve / Deny buttons (7-day signed URLs), expiry date formatted as
  "Day of week, Month Date, Year H:MM AM/PM", and — in `local` env only — raw
  unencoded URLs for reading directly from `laravel.log` (HTML encoding in
  `href` attributes makes `&amp;` appear in the log, breaking copy-paste
  testing).
- `App\Http\Controllers\StudentClaimController` — `approve()` activates pending
  rows and upserts a `school_student` enrollment; `deny()` deletes pending
  rows. Both return 404 on second hit (idempotent). Routes:
  `student-claim.approve` / `student-claim.deny` (signed middleware, 7-day
  expiry).
- `App\Livewire\Students\Index`:
  - `submitStudentClaim()` — creates pending `student_teacher` rows + sends
    emails; auto-approves (attaches immediately) when `activeTeachersFor()`
    returns empty, i.e. no currently active teacher anywhere holds this
    student.
  - `selectStudentClaim()` — enters claim mode; pre-fills `claim_grade` from
    the grade already typed in the add form; pre-selects the subject if the
    teacher teaches only one at that school (`subjectsForAddSchool()`).
  - `claimWillAutoApprove` (bool) — computed in `selectStudentClaim()` so the
    modal can show either an amber warning ("email will be sent, pending until
    approved") or a blue info callout ("no active teacher — will attach
    directly"). Button label changes to match ("Send request" vs "Add to my
    roster").
  - `activeTeachersFor()` — uses a direct `whereIn` subquery on
    `student_teacher` rather than `whereHas`/`wherePivot`, which silently
    returned empty results when the pivot model used `::using()`.
  - `subjectsForAddSchool()` — returns subjects the teacher is registered to
    teach at the specific add-school, used to default the Subject field in the
    claim modal.
  - `edit()` — privacy guard: loading a pending row's full profile
    (emergency contacts, address, birthday) before approval is blocked with a
    warning toast; the Edit button is also disabled in the blade (PHP guard is
    the backstop).
- Blade: amber "Pending" badge in table/card Status column; Edit disabled for
  pending rows; "Cancel request" label + confirm text for pending rows'
  Remove action.
- `Database\Seeders\SchoolTeacherSeeder` — now also seeds
  `school_teacher_subject` with `subject = 'chorus'` for each seeded
  `school_teacher` row (needed so `subjectsForAddSchool()` has data to
  pre-select).
- Tests: `tests/Feature/StudentClaimControllerTest.php` (class-based, covers
  approve/deny/idempotency/tampered-signature); Phase 2 cases added to
  `tests/Feature/Livewire/Students/IndexTest.php` (auto-approve, pending
  creation, email assertion, Pending badge, `edit()` guard).

**Bugs found and fixed during Phase 2:**

- `whereHas`/`wherePivot` with `::using()` custom pivot returned empty even
  with valid data → switched `activeTeachersFor()` to a direct subquery.
- `trackable_pages` migration had never been run in dev → `TrackVisitedPage`
  middleware threw on every Livewire update request, silently preventing form
  submission (no pending row, no email, no visible error). Fixed by running
  `php artisan migrate`.
- Claim modal always showed "email will be sent" warning even when
  `activeTeachersFor()` would return empty at submit time → added
  `claimWillAutoApprove` flag computed eagerly in `selectStudentClaim()`.

## Forward compatibility: StudentFolder.info self-registration

Once StudentFolder.info ships, students/parents will self-register rather
than always being created top-down by a teacher. This adds a third creation
path into the same `Student` table, alongside school-teacher-created and
studio-teacher-created. Two things to revisit when that work starts:

1. **No change needed to matching itself.** `StudentMatcher` already searches
   all students system-wide regardless of who created them, so a
   self-registered student is just another candidate — Phase 1 and Phase 2
   both already handle this case without modification.
2. **Revisit the cross-org approval routing (Phase 2) and the "zero active
   teachers" auto-attach default (decided above).** Today, "no active teacher
   to ask" is expected to be a rare edge case (an abandoned record). Once
   self-registration exists, it becomes the *common* case for newly
   self-registered students who have no teacher yet — and there's now a much
   better approver available than "nobody, so auto-attach": the student/
   parent's own login. When self-registration ships, Phase 2's approval
   routing should prefer asking the student/parent directly over either
   auto-attaching or emailing a teacher of record, falling back to the
   current auto-attach behavior only for records with no self-service login
   and no active teacher at all.

## Phase 3: what shipped (2026-06-26)

- `App\Console\Commands\MergeStudents` — Artisan command
  `students:merge {winner} {loser} [--force]`.
- Displays a confirmation table (both students' name, email, birthday) before
  acting; `--force` skips the prompt for scripted use.
- All mutations wrapped in a single `DB::transaction` so any FK violation rolls
  back cleanly.
- **school_student**: loser's rows are transferred to the winner unless the
  winner already has a row for the same school, in which case the loser's row
  is deleted (winner's enrollment preserved verbatim).
- **student_teacher**: same collision logic keyed on
  `(teacher_id, school_id, subject)`.
- **emergency_contacts**: all rows (including soft-deleted) bulk-updated to the
  winner's `student_id` via a raw `DB::table()` query that bypasses the
  soft-delete global scope.
- **home_address**: transferred if the winner has none; discarded if the winner
  already has one.
- Loser `Student` record deleted unconditionally. Loser `User` also deleted
  (along with their `page_visits`, `phones`, and `social_accounts`) unless
  they also hold a `Teacher` record, in which case the User is left in place
  with a warning line in the output.
- Tests: `tests/Feature/Console/MergeStudentsTest.php` — 14 cases covering
  guards (bad IDs, same ID, declined confirmation), school_student and
  student_teacher transfer/skip logic, emergency_contact reassignment,
  home_address transfer/discard, loser deletion, loser-User retention when a
  Teacher record exists, and winner record integrity.

## Status

Plan approved 2026-06-24. All three phases implemented, tested, and complete.
