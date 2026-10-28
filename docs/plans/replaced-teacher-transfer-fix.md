# Replaced-teacher transfer fix

Started 2026-10-28. Branch: `feature/replaced-teacher-transfer-fix`.

## Background

Investigation (2026-10-28, prod copy) started from a duplicate student (Olivia Liebnitz,
students 19794 / 21156, double-charged $28 on v88) and traced it to
`ReplacedTeacherStudentTransfer` failing for 8 of the 10 recorded teacher replacements:

1. **The transfer only ran on 2 of 5 verification paths.** `SchoolEmailVerificationController`
   and `Founder\TeacherVerification::verifyTeacher()` called it; `Schools\Index`'s auto-verify
   (school email == already-verified login email), `Founder\AddTeacher::verifySchoolEmailNow()`
   and `DirectorUploadService` did not. Every one of the 9 affected links had
   `school_email == login email`, i.e. they took the auto-verify path. The Schools page even
   toasted "Current students will be transferred to you once your school email is verified."
2. **Exact `users.name` match.** The onboarding wizard collects the replaced teacher as free
   text; `users.name` includes honorifics ("Mr. Brian Williams" vs typed "Brian Williams").
   Robbinsville and Newton would have failed even on the right path. Failure returned 0 silently.
3. **Consequences:** ~57 current students stayed on the old teacher's roster; students who
   couldn't find themselves under the new teacher re-registered, producing ~20 duplicate
   groups (13 directly attributable); the old teacher kept dashboard control of those
   candidates (Williams withdrew Olivia's v88 candidate on 2026-10-28) because teacher candidate
   actions check only `candidates.teacher_id`, not an active school link.
4. Separately, 189 v88 candidates were bulk-inserted 2026-10-11 16:50:50 UTC bypassing
   `CandidateObserver` (no initial history row) and not via any Lambda invocation (CloudWatch
   checked) — i.e. a direct DB connection. 16 of those rows were assigned to a teacher with no
   active+verified link at the school, because the old teachers' `student_teacher` rows were
   never moved.

Affected replacements (school_teacher id → school, new ← old, active-version candidates still under old):

| st id | school | new ← old | stuck students (pre-verify) | active cands |
|---|---|---|---|---|
| 476 | 2458 Robbinsville HS | 20439 Ebersole ← 13542 Williams | 11 | 10 (v88) |
| 554 | 3372 Frelinghuysen MS | 20511 Fritz ← 12325 Erlenborn | 21* | 20 (v93) |
| 468 / 472 | 8885 Robert R. Lazar MS | 20434 Dishong / 20436 Kurbansade ← 18737 Green | 19 | 42 (v93+v97) |
| 458 | 8592 Newton HS | 19993 Abdallah ← 278 Malloy | 5 | 5 (v88) |
| 490 | 8775 UCVTS | 20451 Manning ← 19233 Colby | 1 | 0 |
| 475, 409, 463 | East Brunswick, Pequannock, Somerville | — | 0 (rows created after verify) | 0 |

\* inflated by 9th graders who moved up to high school; `school_student.is_active` wasn't filtered.

**Constraint:** v88, v93 and v97 are in active registration. Top priority is no disruption of
candidate registration: nothing may vanish from / appear on a teacher's dashboard without notice.

## Phase 1 — stop new failures (code only; no existing-row changes)

1. **Transfer fires from `SchoolTeacherObserver`** (`created`/`updated`) whenever a link is
   active + verified + names a replaced teacher, and either hasn't been transferred yet
   (`replacement_transferred_at` null) or the replaced teacher was just changed. Direct calls in
   the controller and founder page removed. The count is exposed on the pivot instance for the
   verification page / toasts.
2. **`school_teacher.replaced_teacher_id`** (nullable FK → teachers, null on delete). Wizard and
   Schools page pick from a dropdown of the school's other teachers (wizard keeps a "not listed"
   free-text fallback). Legacy free-text names resolve via a loose match (case, whitespace,
   honorific-insensitive); ambiguous → no transfer.
3. **`school_teacher.replacement_transferred_at`** — set when the transfer runs, so the annual
   verification reset doesn't silently re-run a years-old transfer. **Migration backfills it to
   `verified_at` for existing verified replacement rows**, so deploying Phase 1 changes no
   existing data; those are repaired deliberately in Phase 2.
4. **Safer transfer:** only `school_student.is_active` current students; candidates move only for
   Versions the new teacher holds an invitation to, and never when the candidate has a
   teacher-paid allocation; skipped items and unresolved names are logged (note: prod `Log::`
   doesn't reach CloudWatch — Phase 2's command is the real visibility tool). Old teacher's
   school link is never auto-deactivated.
5. Tests for every verification path, loose/ambiguous matching, invitation and payment skips,
   idempotency, reset re-verification. Full Pest + full PHPStan.

**Status 2026-10-28:** built, uncommitted. Full Pest (1841) + full PHPStan clean. Prod SQL
dry-run against a pre-migration copy of `school_teacher` in strict mode: OK.

Found while building:
- `Teacher::schools()` / `School::teachers()` only load `withPivot()` columns — a pivot loaded
  through them lacked `replacement_transferred_at`, which would have re-run the transfer on
  any Schools-page edit. Both `withPivot()` lists now include the new columns, and the observer
  reads the stamp from the row, never the instance.
- `school_teacher` 471 (teacher 170 @ 2549) had `verified_at = 0000-00-00 00:00:00`; strict mode
  refuses the FK's table rebuild. Migration + SQL set it to `created_at` (app already read it
  as verified).
- Newton's stored name is `Dan  Malloy` (double space) — the old exact match could never hit.
- A "Previously entered: X — pick them from the list to confirm" nudge for legacy typed names
  was **hidden until Phase 2 is done** (decided 2026-10-28): picking runs the transfer
  immediately, ahead of the per-school notice. The legacy name is still kept on save; a teacher
  can still pick from the dropdown unprompted. Restore the nudge after Phase 2.

**Deploy:** migrations don't auto-run on Vapor — apply
`database/sql/2026_10_28_add_replacement_tracking_to_school_teacher.sql` (or `php artisan migrate`)
**before** the code deploy goes live; the new code reads both columns.

## Phase 2 — repair stuck rosters (data, one school at a time)

`teachers:transfer-replaced {school_teacher_id?} {--dry-run} {--students=}` reusing the Phase 1
service; no argument lists every replacement whose old teacher still holds current students.
Per school: dry-run on a fresh prod copy → review → notify both teachers → run on prod → verify.

Order: Robbinsville (exclude Olivia until refund + merge) → Frelinghuysen (confirm movers drop
out) → Newton (**decision:** invite Abdallah to v88 or leave with Malloy) → Lazar MS
(**decision:** is Green gone; Dishong/Kurbansade split; likely explicit `--students`) → the
zero-candidate schools. Also: teacher 208 @ school 4286 bulk-insert row; find the source of
post-verification old-teacher links (East Brunswick, Pequannock, Somerville, UCVTS).

## Phase 3 — merge duplicates (after Phase 2 for that school; after merge branch deploys)

1. Finish `feature/merge-students-keep-candidates` (stashed WIP: `stash@{0}` as of 2026-10-28).
2. Guard: merged `school_student` rows bypass `SchoolStudentObserver` — deactivate the stale
   school link for moved-school students so no one ends up active at two schools.
3. Winner = record holding the paid/uploaded candidate, else the login the family uses.
   Liebnitz → keep 21156, **only after txn 672 shows `refunded`**, then drop Williams's link.
   Vilatoro Taglianeti → keep 19076 (payment on old record). Jaiswal, Kanukuntla, Shabani,
   Duffy → keep the paid new record. Small batches, outside after-school hours.

## Phase 4 — hardening (after each Version's registration window closes)

1. Teacher candidate actions require an active+verified school link (`CoTeacherAccessService`).
2. Identity check at student self-registration (root cause of the duplicates).
3. Backfill initial `candidate_status_history` rows for the observer-bypassed batches
   (2026-10-08 07:16:16, 2026-10-11 16:50:50).

## Open decisions

1. Newton: invite Abdallah to v88, or leave candidates with Malloy?
2. Lazar MS: is Green still there; how to split students between Dishong and Kurbansade?
3. Deactivate the old teacher's school link on transfer automatically, or only on confirmation?
