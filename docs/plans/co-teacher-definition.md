# Co-Teacher Definition — Implementation Plan

Scoped 2026-08-20 from `CoTeacher Definition.docx` (source: `C:\Users\RickRetzko\Documents\products\tdr2027\docs\CoTeacher Definition.docx`) plus a clarifying-question pass with the product owner (§0 below). This follows this project's usual "design doc → implementation" split (see `event-version-orientation.md` / `studentfolder-module.md` / `epayment-integration.md` for precedent).

**Headline finding from review: the concept the docx describes does not exist in this codebase today**, despite two same-named `Primary | Coteacher` enum fields already present. See §1.

---

## 0. What was asked, and what was clarified

**The source doc, verbatim-condensed (it's four short paragraphs):**

> The Co-Teacher concept is grounded in the reality that: (1) one school may employ multiple teachers to teach a subject where multiple teachers will teach the same student in the same subject, and (2) multiple schools (e.g. a high school and middle school in the same district) may share teachers between the schools, resulting in the sharing of students. Co-teaching requires self-identification by the teacher. Co-teaching school(s) must be active to both teachers. Co-teaching is interpreted as: "I agree to share my students (the user-teacher) with you (the co-teacher) with no automatic reciprocation." As a default, co-teachers have equal access and responsibility to all students identified in the `student_teacher` table. All views that identify students (including candidate\*) are equally accessible to all co-teachers of those students/candidates. There is a business requirement that, in co-teaching situations, one teacher may require that all candidates for a specific version are identified under a specific teacher id (`candidates.teacher_id`).

**Clarifying answers that shape this plan:**

| Question | Answer |
|---|---|
| How is a co-teaching grant established — does the recipient have to accept it? | **Unilateral, no approval.** Teacher A picks a co-teacher and access starts immediately; B does nothing to receive it. Matches the docx's literal wording ("I agree to share... with no automatic reciprocation"). B must separately grant back if they want to share their own roster with A. |
| What does a single grant cover — one shared school, or every school the two teachers have in common? | **Per school.** One grant = access to the granter's students specifically at one named school where both are active+verified. Two shared schools need two separate, independently-revocable grants. |
| If either teacher later goes inactive/unverified at the shared school, should the grant stop working automatically? | **Yes, auto-revoke.** Access stops the moment either side is no longer active+verified at that school. Restoring active status later does **not** auto-restore access — it must be re-granted. |
| The per-Version "consolidate under one teacher_id" override — who can set it, and does it rewrite existing candidates or only future ones? | **Either co-teacher, retroactive.** Either party to an active share can set, per Version, "record all our shared candidates under teacher X" — this immediately reassigns `teacher_id` on every already-existing Candidate row it applies to, **and** governs any candidate enrolled into that Version afterward. |

**Consequently, explicitly out of scope for this phase** (flag, don't scaffold): a request/approval workflow for grants (rejected in favor of unilateral); reciprocal/mutual grants being implied by a single action (each direction is its own explicit grant); any StudentFolder.info/candidate-facing equivalent (`Sfdi\Events\Show` already documents itself as deliberately *not* having a "co-student" analog — this feature stays teacher-side only); and any change to the existing `school_teacher.role`/`student_teacher.role`/`replacing_teacher_name` fields, which are a separate, unrelated concept (see §1).

---

## 1. Current implementation review — what's actually there today

**Two pre-existing `Primary | Coteacher` fields, neither of which implements this concept:**

- `school_teacher.role` (migration `2026_06_17_161834_add_role_to_school_teacher_table.php`, paired with `replacing_teacher_name`) — set once, by a teacher describing *themselves* when they link to a school (`Schools\Index::linkExistingSchool()`/`saveEdit()`, `TeacherOnboardingWizard`). It has no FK to any specific counterpart teacher. Its only functional consumer is `App\Support\ReplacedTeacherStudentTransfer::transfer()`, which reads `replacing_teacher_name` (not `role`) to do a one-time succession transfer of a departed teacher's current students to the new one. `role` itself is **read nowhere** — confirmed via grep, it's stored and redisplayed only.
- `student_teacher.role` (migration `2026_06_12_000015_create_student_teacher_table.php`) — set per (student, teacher, school, subject) when a teacher adds an already-rostered student to their own roster (`Students\Index::attachExistingStudent()`/`submitStudentClaim()`). Also has no FK to a counterpart teacher, and is **read nowhere** beyond redisplay in the edit form.

Both are self-descriptive labels a teacher applies to their own row, not a grant between two named teachers. Neither should be touched by this feature — they describe an unrelated fact (how a teacher characterizes their own instructional relationship to a school/student), not an access grant. This plan does **not** rename, remove, or repurpose them, to avoid an unrelated, riskier cleanup inside this change. (Worth a future product question — the shared enum name and near-identical values are a standing source of confusion for the next person who reads this code — but not this phase's problem to solve.)

**The actual access gate today is a strict `candidates.teacher_id` equality check, duplicated across the whole Registrations module** — directly contradicting `event-version-orientation.md` §6.2's own stated design ("`candidates.teacher_id`... is not the access gate"), which was apparently written aspirationally and never implemented:

| File | Line(s) | What it does |
|---|---|---|
| `app/Livewire/Registrations/CandidateDetail.php` | 112, 115 | `abort_if($candidate->teacher_id !== $this->teacher()->id, 403)` — the primary per-Candidate gate. |
| `app/Livewire/Registrations/VersionDashboard.php` | 81, 109, 121, 157, 256, 321, 362, 447 | "My Candidates" roster query, withdraw/refresh/pay actions, allocate-payment candidate picker — all scoped to `where('teacher_id', $teacher->id)`. |
| `app/Livewire/Registrations/Index.php` | 65, 91, 101 | The "Active Candidates" bucket's version list and per-Version candidate counts (§6.2's three-bucket nav). |
| `app/Livewire/Registrations/PitchFiles.php` | 52–54 | `VersionInvitation::where('teacher_id', ...)` invitation-standing gate — corrected during implementation: this page is a Version-wide reference library with its own Voice Part filter dropdown, not scoped to the viewer's own Candidate at all. In scope anyway (see build status below) so a granted co-teacher isn't dead-ended from a link `VersionDashboard`'s quick-links row now surfaces to them. |
| `app/Livewire/Registrations/EstimateForm.php` | 41, 93, 109 | Which schools/candidates appear on the teacher's Estimate Form. |
| `app/Livewire/Registrations/Results.php` | 70–71, 121, 230 | Standing check, results roster, and per-School/per-Person score reports. |
| `app/Livewire/Registrations/ResultsIndex.php` | 43, 53 | Results landing page's Version list and counts. |

Two files are `teacher_id`-scoped for a **different, legitimate** reason and are explicitly **not** in scope for this change — a Version Invitation and an Obligations decision are the acting teacher's own legal/administrative standing with the Event Manager, not "a view that identifies students":
- `app/Livewire/Registrations/RequestInvitation.php`
- `app/Livewire/Registrations/VersionObligations.php`

**`AutoEnrollmentService`/`CandidateService::enroll()`** (`app/Services/AutoEnrollmentService.php`, `app/Services/CandidateService.php:23-40`) always sets `candidates.teacher_id` to whichever teacher's own `student_teacher` row or `VersionInvitation` triggered the enrollment (the "natural" owner) — this is the single choke point every enrollment path funnels through, and is where the consolidation override (§4) hooks in.

---

## 2. Data model changes

Two new tables. No changes to any existing table.

### 2.1 `co_teacher_grants`

One row = one unilateral, per-school grant from a granting teacher to a co-teacher.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigIncrements | no | — | PK |
| `school_id` | foreignId | no | — | FK → schools; cascade on delete |
| `granting_teacher_id` | foreignId | no | — | FK → teachers; cascade on delete — the teacher whose roster is being shared ("I agree to share my students") |
| `co_teacher_id` | foreignId | no | — | FK → teachers; cascade on delete — the recipient |
| `granted_by_user_id` | foreignId | no | — | FK → users; who clicked Grant (should always equal `granting_teacher_id`'s own user, but stored explicitly for audit, matching the `granted_by_user_id`-style convention used elsewhere, e.g. `version_invitations.invited_by_user_id`) |
| `created_at` / `updated_at` | timestamp | yes | null | Laravel timestamps |

`unique(['school_id', 'granting_teacher_id', 'co_teacher_id'])`. A `CHECK`/app-level validation rejects `granting_teacher_id === co_teacher_id`. No `revoked_at`/soft-disable column — matching the existing `version_invitations`/`co_registration_manager_counties` precedent of hard-delete on revoke (manual or auto-triggered by §3's inactivity cascade) rather than a tri-state row; a grant either exists or it doesn't, and "restoring active status does not auto-restore access" (§0) means there's never a reason to want the old row back.

`Teacher::coTeacherGrantsGiven()` (`HasMany`, `granting_teacher_id`) / `Teacher::coTeacherGrantsReceived()` (`HasMany`, `co_teacher_id`) — new relations on `App\Models\Teacher`.

### 2.2 `version_co_teacher_consolidations`

One row = "for this Version, at this school, these two co-teachers' shared candidates are all recorded under one of them."

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigIncrements | no | — | PK |
| `version_id` | foreignId | no | — | FK → versions; cascade on delete |
| `school_id` | foreignId | no | — | FK → schools; cascade on delete |
| `first_teacher_id` | foreignId | no | — | FK → teachers; cascade on delete — the lower of the two teacher ids (app-level canonicalization via `min()`/`max()` at write time, so either co-teacher can set this without needing to know who's "first") |
| `second_teacher_id` | foreignId | no | — | FK → teachers; cascade on delete — the higher of the two |
| `consolidated_teacher_id` | foreignId | no | — | FK → teachers; must equal `first_teacher_id` or `second_teacher_id` (app-level validation, not a DB constraint) |
| `set_by_user_id` | foreignId | no | — | FK → users |
| `set_at` | timestamp | no | — | |
| `created_at` / `updated_at` | timestamp | yes | null | Laravel timestamps |

`unique(['version_id', 'school_id', 'first_teacher_id', 'second_teacher_id'])`. Deliberately **not** FK'd to `co_teacher_grants` — if a grant is later revoked, the consolidation setting stays in place as a historical record (its practical effect just becomes moot, since nothing will re-trigger it going forward once the underlying share is gone and `CoTeacherAccessService` no longer resolves the pair together — see §3). Saving/updating this row is itself the trigger for both the retroactive bulk-reassignment and the forward-looking enrollment behavior — see §4.

---

## 3. Access resolution — `App\Services\CoTeacherAccessService`

New service, single source of truth for "which teachers' Candidates can this teacher see," replacing every bare `where('teacher_id', $teacher->id)` call site listed in §1 that scopes *Candidate visibility* (not the two invitation/obligation files, which stay as-is).

```php
final class CoTeacherAccessService
{
    // Candidate::query() pre-filtered to every Candidate this teacher may
    // view/manage: their own (candidates.teacher_id === $teacher->id), OR
    // one where an active co_teacher_grants row exists from the Candidate's
    // own teacher_id to $teacher, scoped to the Candidate's own school_id.
    public function candidateQuery(Teacher $teacher): Builder;

    // Teacher ids whose Candidates $teacher may see, at a specific school
    // (or across all schools if $schoolId is null) — used by list/count
    // queries that don't already have a Candidate builder in hand.
    public function visibleTeacherIds(Teacher $teacher, ?int $schoolId = null): Collection;

    public function canAccessCandidate(Teacher $teacher, Candidate $candidate): bool;

    // Every other active+verified teacher at $schoolId, minus $teacher
    // themselves and anyone already granted — feeds the "grant a new share"
    // picker (§5).
    public function grantableTeachers(Teacher $teacher, School $school): Collection;
}
```

`candidateQuery()` is the one to actually use everywhere — it's a single `Candidate::where(fn ($q) => $q->where('teacher_id', $teacher->id)->orWhereExists(fn ($sub) => $sub->selectRaw(1)->from('co_teacher_grants')->whereColumn('co_teacher_grants.school_id', 'candidates.school_id')->whereColumn('co_teacher_grants.granting_teacher_id', 'candidates.teacher_id')->where('co_teacher_grants.co_teacher_id', $teacher->id)))` — correctly scoped per-school (a grant at School X never leaks visibility into that same granting teacher's candidates at School Y), and correctly directional (B seeing A's candidates never implies A sees B's).

**Call sites to migrate** (§1's table, minus the two invitation/obligation files): every `Candidate::where('teacher_id', $teacher->id)`/`->where('teacher_id', $this->teacher()->id)` in `CandidateDetail`, `VersionDashboard`, `PitchFiles`, `EstimateForm`, `Results`, `ResultsIndex` becomes `$this->coTeacherAccess->candidateQuery($teacher)` (or `visibleTeacherIds()` where the existing code needs a plain id list rather than a builder, e.g. `EstimateForm`'s school-scoping loop). `CandidateDetail::mount()`'s `abort_if` becomes `abort_unless($this->coTeacherAccess->canAccessCandidate($teacher, $candidate), 403)`.

**`Registrations\Index.php` needs more care than a mechanical swap.** Its "active" bucket (`candidateVersionIds`, `counts`, lines 65/91) is genuinely a candidate-visibility query and should move to `candidateQuery()`. Its "open"/"eligible" buckets and the obligation badge (`$invitations`, lines 100–106) must **stay** strictly `teacher_id`-scoped — those describe the *acting teacher's own* invitation/obligation standing with the Event Manager, which a co-teaching grant does not delegate (§0 explicitly scoped this feature to student-identifying views, not invitation standing). Net effect: a co-teacher with no invitation of their own to a Version can still see it in the "Active Candidates" bucket (to manage the shared candidates there) but will never see it in "Open for Registration"/"Invitation Available" on the strength of the grant alone.

**Auto-revoke (§0).** New `App\Observers\SchoolTeacherObserver::updated()` — fires on any `school_teacher` row transitioning `is_active` to `false` or `verified_at` to `null`. Deletes every `co_teacher_grants` row for that `(school_id, teacher_id)` in **either** direction (`granting_teacher_id` or `co_teacher_id`) — a teacher who goes inactive can no longer grant, and can no longer receive, at that school. No new grant is auto-created on reactivation (§0: "must be re-granted").

---

## 4. Consolidation override

New Livewire action, `setTeacherConsolidation(Version $version, Teacher $consolidatedTeacher)`, reachable from wherever §5's UI lands it (see below) — available to either of two teachers with an active `co_teacher_grants` row between them at the Candidate's school, for a Version both currently have visible candidates in.

On save (`App\Services\CoTeacherConsolidationService::set()`):
1. `updateOrCreate` the `version_co_teacher_consolidations` row (canonicalizing `first_teacher_id`/`second_teacher_id` via `min()`/`max()` per §2.2).
2. **Retroactive step** (§0): `Candidate::where('version_id', $version->id)->where('school_id', $school->id)->whereIn('teacher_id', [$teacherA->id, $teacherB->id])->where('teacher_id', '!=', $consolidatedTeacher->id)->update(['teacher_id' => $consolidatedTeacher->id])` — every existing Candidate belonging to either side of the pair, at that school, in that Version, is reassigned. This is a direct bulk `update()`, not a per-row `save()` — unlike `TeacherStudentTransferService`'s `student_teacher` moves (which need `StudentTeacherObserver` to fire for auto-enrollment), a `teacher_id` change on an already-existing `Candidate` has no observer cascade to preserve, so the simpler bulk statement is safe here.
3. **Forward step**: `CandidateService::enroll()` (the single choke point, §1) gains a new first step — before writing `teacher_id`, check `App\Services\CoTeacherConsolidationService::resolveTeacherId(Version $version, int $schoolId, Teacher $naturalTeacher): Teacher`, which looks up an active consolidation row for `(version, school, naturalTeacher)` and returns the consolidated teacher instead if one exists, else `$naturalTeacher` unchanged. This makes every future auto-enrollment (new roster member, newly-eligible student, etc.) land under the consolidated teacher automatically, with no changes needed to `AutoEnrollmentService`'s own callers.

**Undoing a consolidation.** Deleting the `version_co_teacher_consolidations` row stops future enrollments from being redirected, but — per this project's existing precedent of never silently un-doing a prior write (e.g. Obligations' "unpublish doesn't touch existing responses," §5.6 of `event-version-orientation.md`) — does **not** retroactively move already-consolidated Candidates back. Flagged as a real, deliberate asymmetry: consolidating is retroactive, un-consolidating is not. Worth confirming with the product owner before building if that's actually the desired behavior, or if un-consolidating should offer the same retroactive re-split.

---

## 5. UI

**Granting/revoking** — new "Co-Teachers" action on each school row of `Schools\Index` (`resources/views/livewire/schools/index.blade.php`), visible only when that row is active+verified for the acting teacher. Opens a modal with two sections:
- **Teachers I've granted access to** — list with a Revoke button per row (`CoTeacherGrant::where('school_id', ...)->where('granting_teacher_id', $teacher->id)`).
- **Grant access to a new co-teacher** — a `flux:select` populated by `CoTeacherAccessService::grantableTeachers()` (every other active+verified teacher at this school, minus ones already granted), plus a Grant button. Immediate effect, no confirmation step beyond the button itself (unilateral, §0) — though a `wire:confirm` is worth considering given the breadth of access this hands over; flagged as an implementation-time call, not pre-decided here.
- A read-only third section, **Teachers who've granted me access**, so a teacher can see the reverse direction too (`CoTeacherGrant::where('school_id', ...)->where('co_teacher_id', $teacher->id)`) without being able to revoke someone else's grant from here.

This reuses the existing `Schools\Index` page/component rather than a new route — consistent with how that page already hosts every other per-school teacher action via modals, and keeps the grant naturally anchored to "the school both of us are active at," which is also the grant's actual scope.

**Consolidation** — surfaced on `VersionDashboard` (`app/Livewire/Registrations/VersionDashboard.php`), since that's the page that's already Version+roster-scoped and already shows "my candidates" for a specific school's worth of students. A small panel, visible only when the acting teacher has at least one active grant (either direction) at a school participating in this Version: "You co-teach with {name} at {school} — consolidate all shared candidates under: ( ) Me ( ) {name} [Apply]". Exact placement/visual treatment is an implementation-time UI call, not pre-decided here — flag for a real mockup/screenshot pass before building if the product owner wants to review it first, matching how `Estimate Form`/`Web Registration Manager Module` were scoped from real sample documents.

---

## 6. Authorization

- Granting a share: the acting teacher must themselves be active+verified at the target school (`hasActiveSchool()`-style check, scoped to that one school) — enforced in `CoTeacherAccessService`/the new Livewire action, not just implied by the picker being pre-filtered.
- `grantableTeachers()` only offers teachers who are *also* active+verified at that same school — "Co-teaching school(s) must be active to both teachers" (§0) is enforced at grant time via this filter, and on an ongoing basis via §3's auto-revoke observer.
- Revoking a share: only the original `granting_teacher_id` can revoke their own grant (not the recipient, not a shared-admin concept — this mirrors the strictly one-directional ownership already established by "no automatic reciprocation").
- Consolidation: either of the two teachers party to the underlying grant (in either direction) may set or change it — per §0's answer, this is intentionally *not* restricted to the granter only.
- No new Spatie role or Founder/Event-Manager-level gate is needed anywhere in this feature — it's entirely teacher-to-teacher self-service, same posture as `Students\Index`/`Schools\Index` themselves.

---

## 7. Open questions / assumptions to confirm before or during implementation

1. **UI placement for both screens (§5)** — proposed inline on `Schools\Index` and `VersionDashboard` respectively, reusing existing pages rather than new routes. Flag for confirmation/a real mockup pass, not locked in.
2. **Un-consolidating is not retroactive (§4)** — a deliberate asymmetry inferred from existing precedent, not explicitly asked about. Confirm before building, since "either co-teacher, retroactive" (§0) was only confirmed for the *setting* direction.
3. **`wire:confirm` on Grant** — unilateral grants hand over full parity access with one click and no recipient approval; worth a confirmation dialog given the blast radius, but not specified by the docx or the clarifying pass. Flag for a product-owner call.
4. **The existing `school_teacher.role`/`student_teacher.role`/`replacing_teacher_name` naming collision** (§1) — not fixed by this plan, flagged as a legitimate future cleanup question (rename or otherwise disambiguate from the new `co_teacher_grants` concept) rather than silently touched here.
5. **`Registrations\Index`'s three-bucket split (§3)** — the "active" bucket picks up co-teacher-visible Versions; "open"/"eligible" deliberately do not. Confirm this reading matches intent — it means a co-teacher can manage shared candidates in a Version they were never personally invited to, but won't see that Version surfaced as something *they* can request/accept an invitation for.

---

## 8. Phased build order

1. ~~**Data model** (§2): `co_teacher_grants`, `version_co_teacher_consolidations` migrations + models + `Teacher` relations.~~ **Built 2026-08-20.**
2. ~~**`CoTeacherAccessService`** (§3), fully unit/feature tested against a matrix of: natural ownership, granted access (same school), no access (different school / no grant), auto-revoked-on-inactivity.~~ **Built 2026-08-20** — `candidateQuery()`/`canAccessCandidate()`/`visibleTeacherIds()`/`grantableTeachers()`, 10 tests in `CoTeacherAccessServiceTest.php`.
3. ~~**Migrate call sites** (§1/§3's table)~~ **Built 2026-08-20** — all seven files migrated: `CandidateDetail` (plus a real bug fix along the way — its obligations-gate invitation lookup was keyed to the *viewer's* teacher_id, which would 404/loop a granted co-teacher with no invitation of their own; now keyed to `candidate->teacher_id`, the actual roster owner), `VersionDashboard` (`mount()` gains a granted-candidate bypass around the invitation/obligations requirement, mirrored in `PitchFiles`/`EstimateForm` since both are reachable from its quick-links row), `EstimateForm`/`EstimateFormData::build()`, `Results`/`ResultsIndex`, and `Registrations\Index`'s "active" bucket only (`open`/`eligible` deliberately left untouched, confirming §3's design). 15 new regression tests across the seven files' existing test suites, all pre-existing tests still green.
4. ~~**`SchoolTeacherObserver`** auto-revoke (§3).~~ **Built 2026-08-20** — plus a real bug fix found in the process: `Schools\Index::deactivate()`/`saveEdit()` wrote `school_teacher` via `updateExistingPivot()`, a raw query-builder statement that skips Eloquent model events entirely, so the observer would never have fired on the most common path. Both call sites (plus `activate()`) now go through a real model `update()`. 6 tests in `SchoolTeacherObserverTest.php`; existing `Schools\Index` suite (52 tests) still green.
5. ~~**Grant/revoke UI** on `Schools\Index` (§5).~~ **Built 2026-08-20** — a "Co-Teachers" action added to each active+verified school row (mobile card + desktop dropdown, matching the existing Deactivate/Activate visibility rule), opening a `co-teachers` modal with three sections: grants given (with Revoke), a grant-a-new-co-teacher picker (`Rule::in()` against `CoTeacherAccessService::grantableTeachers()`, unilateral/immediate per §0 — no `wire:confirm`, flagged in §7 item 3 as still open), and a read-only "granted to me" list. 13 new tests in `Schools\IndexTest.php` covering authorization, the grantable-teachers scoping (per-school, excludes self/already-granted), and revoke ownership (can't revoke someone else's grant).
6. ~~**Consolidation** — `version_co_teacher_consolidations`, `CoTeacherConsolidationService`, the `CandidateService::enroll()` hook (§4), and its `VersionDashboard` UI (§5).~~ **Built 2026-08-20** — `CoTeacherConsolidationService::set()` (canonicalizes `first_teacher_id`/`second_teacher_id` via `min()`/`max()`, retroactively bulk-reassigns existing Candidates via a direct `update()`, not a per-row `save()`) and `resolveTeacherId()` (consulted from `CandidateService::enroll()`, resolved via the container rather than a constructor dependency so the many existing `new CandidateService` call sites didn't need updating); `relevantPairings()` feeds a new panel on `VersionDashboard` — one row per (school, other co-teacher) with at least one shared candidate in this Version, two buttons ("Consolidate under me" / "Consolidate under {name}"), each `wire:confirm`-gated given the retroactive bulk-reassignment behind it (unlike granting, which stayed unconfirmed per §0's "unilateral" framing — consolidation actually moves existing data, judged a real enough consequence to warrant a confirm). `setTeacherConsolidation()` re-verifies a grant actually exists between the two teachers (either direction) before calling `set()` — defense in depth beyond the panel only ever being rendered for a real pairing. 12 tests in `CoTeacherConsolidationServiceTest.php`, 4 more in `VersionDashboardTest.php`; existing suites (`CandidateServiceTest`, `AutoEnrollmentTest`, full `VersionDashboardTest`) all still green. Two real PHPStan/Larastan quirks hit and resolved along the way: `abort_unless(in_array(...), 422)` false-flagged as "will always evaluate to true" (rewritten as `if (!...) { abort(422); }`, no such complaint) — narrower than the codebase's existing cataloged quirks, worth a mental note but not chased further; and the `Collection<int, array{...}>` return-type covariance issue already documented in `feedback_phpstan_quirks` recurred here too, fixed the same way (`covariant` in the docblock, per `VersionDashboard::paymentRegisterRows()`'s existing precedent).
7. ~~Regression pass~~ **Done incrementally with each step, not as a separate pass** — every step's regression tests were added and verified alongside its own build rather than batched at the end. Full app-wide PHPStan and Pint clean throughout. A full-suite run mid-build (1361 tests) surfaced 2 failures in `SidebarNavigationTest.php` caused by editing `Schools\Index`'s view while that 15-minute run was still in flight (a stale-render race, not a real defect) — confirmed passing in an isolated re-run once the edits settled, and again in a clean full-suite re-run with no concurrent edits (1368/1368, 3467 assertions) before starting step 6. **Final full-suite run after step 6, confirmed: 1384/1384 passing, 3495 assertions.**

**All seven build-order steps now built.** `docs/plans/co-teacher-definition.md` itself still carries three genuinely open items from §7 below (UI placement was resolved in practice — both screens reused existing pages, matching the proposed default; the un-consolidate asymmetry, the grant `wire:confirm` question, and the `TeacherRole`/`co_teacher_grants` naming-collision cleanup remain unconfirmed with the product owner).

**Discovered live 2026-08-20, resolved same day:** the product owner tried to see the consolidation panel via an existing `school_teacher.role = coteacher` relationship between two real teachers and found nothing, since no `co_teacher_grants` row existed — exactly the naming-collision confusion flagged in §7 item 4 above, now confirmed to bite in practice, not just in theory.

8. **Spotlight tour step (post-build polish, not in the original §8 order)** — the consolidation panel's `id="tour-co-teacher-panel"` wrapper was added to `VersionDashboard`'s existing spotlight tour (`resources/views/livewire/registrations/version-dashboard.blade.php`), positioned right after "Payment Register" to match its on-page position. No new JS logic needed — the tour engine's existing `resolveEl()`/`activeSteps` graceful-degradation filter (element must exist in the DOM and be visible) already drops any step whose target isn't present, and the panel itself is only rendered via `@if ($coTeacherPairings->isNotEmpty())`, so the step naturally appears only for a teacher with a real, candidate-backed co-teaching pairing in that Version. 1 new test in `VersionDashboardTest.php` (anchor present when a pairing exists, absent when none does) — the JS-level step-skipping itself isn't (and can't be, per this project's no-browser-testing convention) exercised by a Pest test.
9. **School filter (product-owner-driven, not in the original §8 order), built 2026-08-20** — `VersionDashboard` and `Results` both gained a `schoolFilter` `#[Url]` property, a dropdown (conditionally shown only once a teacher's visible roster spans more than one school — most often now via a co-teaching grant), a School column on desktop, and a school line on mobile cards. Both reuse `CoTeacherAccessService::candidateQuery()`, so the filter correctly covers a teacher's own candidates plus anything visible via a grant. 4 new tests (2 per file). Full app-wide suite reconfirmed clean afterward: 1389/1389.
10. **Third instance of the bulk-update-skips-observers bug, found and fixed 2026-08-21** — `Founder\TeacherVerification::resetAllAndSendEmails()` (a pre-existing, already-shipped "reset every teacher's school verification at the start of the year" feature, `644b2bf`, predates this whole co-teacher effort) used `SchoolTeacher::whereNotNull('school_email')->update(['verified_at' => null])` — a bulk query-builder statement, same class of bug as `Schools\Index::deactivate()`/`saveEdit()` (§8 item 4 above). Fixed the same way: fetch the pivots first, then `$pivot->update(['verified_at' => null])` per row inside the existing email-queueing loop, so `SchoolTeacherObserver` actually fires and auto-revokes any `co_teacher_grants` resting on a teacher whose verification just lapsed — previously, running the annual reset would have silently left every co-teaching grant in place, contradicting "must be active to both teachers, on an ongoing basis." 5 new tests in a new `TeacherVerificationTest.php` (this component had zero prior coverage), including a direct regression guard asserting a grant is actually gone after the reset. **Real mid-build mistake, caught before it reached the user:** the new test file declared its own `makeFounderUser()` helper, not realizing `ImpersonateTest.php` (same directory) already had one — Pest loads all test files into one process for a full-suite run, so this fatal-errored the *entire* suite (not just the new file) on the first full run after this fix. Passed standalone, failed the instant it ran alongside the rest of the suite. Fixed by deleting the duplicate and relying on the one already declared; recorded in a new memory (`feedback_pest_shared_global_functions`) so it isn't repeated.

**Codebase-wide sweep done 2026-08-21, after the third occurrence.** Grepped every `SchoolTeacher::where(...)` and `updateExistingPivot(` call site app-wide (`TeacherOnboardingWizard`, `Students\Index` ×2, plus the ones already fixed) and a raw `DB::table('school_teacher')` search — no further bulk-write instances found; every remaining call site is a read (`->first()`/`->pluck()`) followed by a proper per-instance `->update()` elsewhere, or no write at all. The three fixed instances (`Schools\Index::deactivate()`/`saveEdit()`, `Founder\TeacherVerification::resetAllAndSendEmails()`) were the only ones.

---

## 9. Test plan outline

- `CoTeacherAccessServiceTest` — the core matrix from §8 step 2, plus school-scoping (a grant at School X never leaks into School Y) and directionality (B seeing A's candidates never implies A sees B's).
- `SchoolTeacherObserverTest` (or folded into an existing observer test file) — deactivating/unverifying either side deletes grants in both directions; reactivating does not restore them.
- Per migrated file (§3): a same-teacher-only regression case (unchanged behavior) plus a new granted-co-teacher case (can now see/act) and a no-grant-different-school case (still 403/empty).
- `CoTeacherConsolidationServiceTest` — retroactive reassignment scope (exactly the two teachers, exactly that school+Version, nothing outside it touched), forward-looking `CandidateService::enroll()` resolution, and canonicalization (`first_teacher_id < second_teacher_id` regardless of which teacher invoked `set()`).
- `Schools\Index` grant/revoke Livewire tests — authorization (can't grant if not active+verified at the school; can't grant to a teacher who isn't; can't revoke someone else's grant), and the grantable-teachers list excluding already-granted/self.
