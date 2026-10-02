# Version Readiness Checklist — Plan & Clarifying Questions

**Status:** Phases A + B **built 2026-10-02** (not yet committed). Clarifying
questions answered — see §9a. Build notes and deviations from the draft in §10.
Phases C (shape interview) and D (templates) not started.

**Problem:** A first-time Event Manager has to make roughly **75–125
configuration decisions** across Event setup, Ensembles, the 8 `VersionEdit` tabs,
Rubric, Rooms, Pitch Files and Invitations. Today those decisions come up
haphazardly or in a panic when something turns out to be missing. Much of TDR's
vocabulary ("Version", "class-of", "cutoff strategy", "obligations",
"pitch file visibility") has to be learned before the decisions even make sense.

**Approach chosen:** a **computed readiness checklist** (the "punchlist"), backed
by a `VersionReadiness` registry. Later phases add an optional short **shape
interview** to prune it and **starter templates** to seed it. See §8.

---

## 1. Decision inventory (where the 75–125 comes from)

| Area | Source in code | Decisions |
|---|---|---|
| Event identity: name, short name, logo, frequency, managers | `CreateEvent`, `events` | ~5 |
| Ensembles: name, grades, voice parts, order × 2–4 | `Events\Show` Ensembles tab, `version_ensemble_order` | 8–16 |
| Scoring rubric: accept event default or customize | `VersionScoringRubric`, `score_categories`/`score_factors` | 1–10 |
| E-payment vendor + credentials (Event-scoped) | `event_epayment_configs` | 1–4 |
| Version › General | `VersionEdit::saveGeneral()` (14 fields + eligible grades) | ~15 |
| Version › Requirements | 8 student-data booleans, membership, counties | ~11 |
| Version › Dates | `VersionDateType` (9 types, start/end) | 9–18 |
| Version › Fees | `version_fees` (5 amounts) | 5 |
| Version › Application, Obligations | content + publish | 4–5 |
| Version › Payments, Roles, Mail-To | `version_epayment_configs`, role assignment, `version_mail_to_addresses` | 5–8 |
| Upload files, Pitch files, Rooms/Judges, Invitations | own screens | 3–20 |

After pruning (remote auditions → no rooms or timeslots; `upload_type=none` → no
upload slots; no e-pay → no vendor) and accepting defaults, about **35–50 real
judgment calls** remain for a first event. A cloned later year needs about **15–25**.

---

## 2. Architecture

### 2.1 Core types

```
app/Readiness/
├── ReadinessPhase.php          enum   — when the item must be done (§2.2)
├── ReadinessStatus.php         enum   — computed state (§2.3)
├── ReadinessItem.php           abstract class / contract (§2.4)
├── ReadinessResult.php         readonly DTO — item + status + detail string
├── VersionReadiness.php        service — evaluates all items for a Version
└── Items/
    ├── Event/…                 one class per item (or small grouped classes)
    └── Version/…
```

(Namespace is open; see Q14. `app/Services/Readiness/` would match the existing
`app/Services/*` convention.)

### 2.2 `ReadinessPhase` (grouped by when it's needed, not by tab)

| Case | Label | Meaning |
|---|---|---|
| `Structure` | Event structure | Event-level shape: ensembles, grades, voice parts, rubric. Usually done once per Event. |
| `BeforeInvitations` | Before inviting teachers | What teachers see the moment they're invited: dates, fees, obligations, requirements. |
| `BeforeRegistration` | Before registration opens | Application, payments, upload slots, pitch files, roles. **Gate for Sandbox → Active** (§5). |
| `BeforeAuditions` | Before auditions | Rooms, judges, timeslots, adjudication dates. |
| `BeforeResults` | Before releasing results | Cutoff strategy, score order, share-results decision. |

Each phase can have a soft "due by" hint taken from `version_dates` (e.g.
BeforeRegistration is due before the earliest `Teacher`/`Candidate` start date).
See Q6.

### 2.3 `ReadinessStatus`

| Case | Meaning | Counts as complete? |
|---|---|---|
| `NotStarted` | Required, no data | No |
| `InProgress` | Partially configured (e.g. 3 of 9 dates set) | No |
| `NeedsReview` | Holds a default or cloned value nobody has confirmed | Configurable, see Q2 |
| `Done` | Data present and valid, or explicitly acknowledged | Yes |
| `NotApplicable` | `isApplicable()` returned false. Hidden by default, with a "show N hidden" toggle | Yes |
| `Waiting` | Depends on someone else (Square owner, a judge accepting, etc.) | No, but shown neutrally |

### 2.4 `ReadinessItem` contract (sketch)

```php
abstract class ReadinessItem
{
    abstract public function key(): string;              // 'version.dates.adjudication' — stable, used for acks
    abstract public function phase(): ReadinessPhase;
    abstract public function question(): string;         // plain-language: "When will judges score?"
    abstract public function why(): string;              // one sentence: what breaks if skipped
    abstract public function status(Version $version): ReadinessStatus;
    public function isApplicable(Version $version): bool { return true; }
    public function isBlocking(): bool { return true; }  // participates in the Sandbox→Active gate
    public function acknowledgeable(): bool { return false; } // can "accept default" (§3)
    abstract public function url(Version $version): string;  // deep link to tab + field anchor
    public function detail(Version $version): ?string { return null; } // "3 of 9 dates set"
    public function coversFields(): array { return []; } // ['versions.audition_type', …] — §6 coverage test
}
```

Items stay **read-only and side-effect free**. They read existing models and never
write.

### 2.5 `VersionReadiness` service

- `evaluate(Version): Collection<ReadinessResult>`. Eager-loads every relation the
  items need in a single pass to avoid N+1: dates, fees, counties, classOfs,
  ensembleOrder, application, obligation, epayment configs, uploadFiles,
  pitchFiles, rooms.judges, invitations, roles.
- `summary(Version)`: counts per phase and an overall percent complete.
- `blockers(Version, ReadinessPhase $upTo)`: incomplete blocking items at or before
  a phase. Used by the status gate.
- Items are registered in one array in a service provider (or one config file), so
  there's a single list to read.

### 2.6 Deep links

`VersionEdit` uses `flux:tab.group` with named tabs. That needs a `?tab=dates`
query parameter mapped to the active tab (check whether `#[Url]` is already used
there), plus optional `#field-id` anchors. Items that live on other screens (Rooms,
Rubric, Pitch Files, Invitations, Co-Reg Managers, Events Show → Ensembles) link
to those routes.

---

## 3. "Accept the default" acknowledgements

A computed status can't tell "the manager chose `judge_count = 1`" apart from
"`judge_count = 1` because nobody looked at it". Many decisions **are** fine at
their default, so the manager needs a one-click **"Looks right"** that records a
decision was made.

Proposed table (version-scoped, so it needs the `version_` prefix):

```
version_readiness_acknowledgements
  id, version_id (FK cascade), item_key (string), user_id (FK nullOnDelete),
  acknowledged_at, value_fingerprint (string, nullable), timestamps
  unique(version_id, item_key)
```

`value_fingerprint` is a hash of the item's current values. If the values later
change on the edit tab, the acknowledgement is superseded by the real edit, which
counts as Done anyway. If the values are reset to the default, it goes back to
NeedsReview. Whether this is worth the complexity is Q3.

**Cloning:** `VersionCloningService::cloneFrom()` copies values but **not**
acknowledgements. So every cloned value shows as `NeedsReview` until confirmed,
which is the "don't silently inherit last year" safeguard. See Q4.

---

## 4. Draft item catalog

`A` = applicability rule, `D` = Done rule. Every item below is **blocking**
unless marked *(soft)*. Field names come from the current schema.

### 4.1 Structure (Event-level; shown on every Version of the Event)
| Key | Plain question | A / D |
|---|---|---|
| `event.ensembles` | What ensembles will students be placed into? | D: ≥1 ensemble |
| `event.ensemble_grades` | Which grades can sing in each ensemble? | D: every ensemble has ≥1 grade |
| `event.ensemble_voice_parts` | Which voice parts does each ensemble use? | D: every ensemble has ≥1 voice part |
| `event.logo` *(soft)* | Do you want your logo on applications and reports? | ack-able |
| `version.ensemble_order` | In what order are ensembles filled? | A: >1 ensemble · D: order rows exist |
| `version.rubric` | How will judges score? (categories & factors) | D: ≥1 category with ≥1 factor (event default counts) · ack-able |

### 4.2 Before inviting teachers
| Key | Plain question | A / D |
|---|---|---|
| `version.identity` | What is this year's name? Which class graduates this year? | D: name + `senior_class_of` set, and `senior_class_of` ≥ current school year (catches an un-bumped clone) |
| `version.eligible_grades` | Which grades may audition? | D: ≥1 `version_class_ofs` |
| `version.counties` | Which counties are in your area? | D: ≥1 `version_counties` (see Q8: required for every event?) |
| `version.dates.teacher` / `.candidate` | When can teachers / students register? | D: VersionDate row with start+end |
| `version.dates.postmark` | When must paper packets be postmarked? | A: `application_type=pdf` (Q7) |
| `version.dates.final_changes` | When does the roster lock? | D: row exists |
| `version.fees` | What does registration cost? | D: `version_fees` row exists · ack-able (a $20 default exists) |
| `version.fees.participation` | Do accepted students pay a participation fee? | ack-able (0 is valid) |
| `version.requirements.student_fields` | What do you need to know about each student (birthday, height, shirt size, address)? | ack-able |
| `version.requirements.emergency_contact` | What emergency contact info is required? | ack-able |
| `version.membership` | Must teachers hold a membership card? | ack-able |
| `version.obligations` | What must teachers agree to? | D: obligation `status=published` (Q9: required?) |
| `version.invitations` | Which teachers/schools are invited? | D: ≥1 invitation · NB: this is the *action*, so it may sit at the end of this phase |

### 4.3 Before registration opens (gate for Sandbox → Active)
| Key | Plain question | A / D |
|---|---|---|
| `version.audition_format` | In-person or recordings? | D: ack or non-default · drives applicability below |
| `version.application` | What does the student application look like? | D: application published (A: `eapplication` or `pdf`, both use `version_applications`; confirm, Q10) |
| `version.upload_files` | What recordings must students submit? | A: `upload_type≠none` · D: ≥1 `version_upload_files` |
| `version.pitch_files` | What practice/pitch files do students get? | D: ≥1 pitch file · *(soft?)* Q11 |
| `version.caps` | Any limit on registrants per school or overall? | ack-able |
| `version.epayment.decision` | Will you accept online payments? | ack-able (off is valid) |
| `event.epayment.credentials` | Connect your Square/PayPal account | A: e-pay on for students or teachers · D: vendor + secret + webhook key present · `Waiting` when vendor set but unverified |
| `version.roles.registration_manager` | Who runs registration? | D: exactly one Registration Manager |
| `version.roles.mail_to` | Where do teachers mail paperwork? | A: `application_type=pdf` OR membership card required · D: RM has `version_mail_to_addresses` |
| `version.roles.co_registration` *(soft)* | Will county co-managers help? | ack-able |
| `version.live_payments_sandbox` | (existing warning) Live payments are on while in Sandbox | Surfaces `Version::livePaymentsWhileSandbox()` as a warning item, not a todo |

### 4.4 Before auditions
| Key | Plain question | A / D |
|---|---|---|
| `version.dates.adjudication` | When will judges score? | D: date row |
| `version.dates.tab_room` | When does the tab room run? | D: date row |
| `version.audition_timeslot` | How long is each audition? | A: in_person · D: >0 |
| `version.timeslots` | When does each school audition? | A: in_person · D: every invited school has a slot (Q12) |
| `version.judge_count` | How many judges per room? | ack-able |
| `version.rooms` | What audition rooms are there? | D: ≥1 room (A: in_person only? or remote rooms too? Q13) |
| `version.room_judges` | Who judges in each room? | D: every room has `judge_count` judges assigned |
| `version.roles.tab_room` | Who runs the tab room? | D: ≥1 Tab Room Manager |

### 4.5 Before releasing results
| Key | Plain question | A / D |
|---|---|---|
| `version.score_order` | Is a low score or a high score better? | ack-able |
| `version.cutoff_strategy` | How are students placed into ensembles? | D: not null |
| `version.share_results` | Share anonymized results with all teachers? | ack-able |
| `version.dates.rehearsal` / `.participation_fee` *(soft)* | Rehearsal and participation-fee dates | ack-able |

The catalog has about **40 items**, which collapse the ~100 raw decisions. Grouping
granularity is Q5.

---

## 5. Sandbox → Active gate

- In `VersionEdit::saveGeneral()`, when `status` moves **from Sandbox to Active**,
  call `VersionReadiness::blockers($version, BeforeRegistration)`. If there are
  any, **don't save the status change**. Add a validation error on `status` that
  lists the blockers with links. Other General fields in the same save still apply
  (or reject the whole save, see Q1).
- Follow the guarded-action rule: the Status select stays fully usable. The
  explanation appears after the attempt, not as a pre-disabled control.
- Founder bypass? See Q1.
- Active → Closed is **not** gated here. Close Audition already owns that path.

---

## 6. Keeping the registry honest (maintenance guard)

A Pest test reflects over `Version::$fillable`, plus a curated list of child
tables, and asserts that every field appears in some item's `coversFields()` or in
an explicit `IGNORED_FIELDS` allowlist (e.g. `results_released_at`, `status`,
`event_id`). Adding a new Version setting without a readiness item then fails CI,
which addresses the checklist's main long-term risk.

Other tests:
- One unit test per item: status for an empty Version, a fully-configured one, and
  one where the item doesn't apply.
- Feature test for the status gate in `VersionEditTest`.
- Clone test: a cloned Version has zero acks, and the ack-able items show `NeedsReview`.
- Query-count test: `evaluate()` stays under N queries (guards against N+1).

Reminder: Pest helper functions are global across the suite. Prefix any helpers
in the new test files (e.g. `readinessVersion()`) so they can't collide with
existing ones.

---

## 7. UI

- **Readiness card**: progress ring/bar plus per-phase counts, with a "Continue
  setup" button pointing at the first incomplete item. Placement is Q15.
- **Checklist page** `events/versions/{version}/readiness`: phase sections as
  collapsible groups. Each row shows status badge, plain question, `why()`
  (tooltip or second line), detail, and either a link or a "Looks right" ack
  button. Below `md:` it renders as cards, and as a Flux table at `md:+`
  (responsive table standard).
- Status badge colors use paired light/dark classes as single literal strings
  (dark-mode shading rule).
- Acks fire a `Flux::toast` ("Fees marked as reviewed.").
- Include the new page in the spotlight-tour engine later. Out of scope for v1.
- Founder view: possibly a readiness column on `Founder\EventDeadlines` (Q16).

---

## 8. Phased build order

1. **Phase A (MVP):** enums, contract, service, ~40 items, checklist page plus
   card, deep-link `?tab=` support on `VersionEdit`, coverage test. No acks, no
   gate. Items that would be ack-able show `NeedsReview` with a link.
2. **Phase B:** `version_readiness_acknowledgements` plus "Looks right", clone
   behavior, and the Sandbox → Active gate.
3. **Phase C:** shape interview, 8–10 plain-language questions shown when a
   brand-new Event's first Version is created. Writes real field values
   (audition_type, upload_type, application_type, ensemble count, e-pay on/off,
   paper packet yes/no) and acks those items. Applicability does the pruning
   automatically.
4. **Phase D:** starter templates. Anonymized template Versions cloned through
   `VersionCloningService`, with everything landing as `NeedsReview`.

---

## 9a. Resolved decisions (2026-10-02)

| Q | Decision |
|---|---|
| 1 | **Hard block** of Sandbox → Active for Event Managers; Founder bypasses; other General fields still save. |
| 2 | `NeedsReview` does **not** block the gate. |
| 3 | No fingerprinting — acks are simple, permanent per Version. |
| 4 | Cloned Versions: only **year-sensitive** items start `NeedsReview` (dates, senior class/grades, fees, rooms/judges, invitations, roles); other ack-able items are auto-acknowledged on clone. |
| 5 | ~40 items as drafted. |
| 6 | Due-by hints: BeforeInvitations/BeforeRegistration ← earliest Teacher start; BeforeAuditions ← Adjudication start. |
| 7 | **Required dates:** Teacher + Candidate (BeforeInvitations); PostmarkDeadline only when `application_type=pdf`; Adjudication + TabRoom (BeforeAuditions). Admin, FinalTeacherChanges, ParticipationFee, Rehearsal are soft/ack-able (Admin/FinalTeacherChanges/ParticipationFee are unreferenced by code today). |
| 8 | *(from code)* Counties optional — no rows = unrestricted (`VersionInvitationEligibilityService`). Soft, ack-able. |
| 9 | Obligations: soft. |
| 10 | *(from code)* Both application types need a published `version_applications` row — required. |
| 11 | Pitch files: soft. |
| 12 | *(from code)* `VersionTimeslot` has no management UI — **excluded from v1**. |
| 13 | *(from code)* Rooms required for both audition types (`Adjudicate` is room-based for remote too). |
| 14 | Namespace `app/Services/Readiness/`. |
| 15 | Card on VersionEdit + mini bar on Events Show + dedicated checklist page. |
| 16 | Event Manager + Registration Manager; Founder column deferred. |
| 17 | Event-level Structure items shown (computed) on every Version. |
| 18 | Shape interview (Phase C) auto-offered on first Version, re-runnable. |
| 19 | Templates deferred to Phase D. |
| 20 | Claude drafts all `question()`/`why()` copy; user edits. |

## 9. Clarifying questions (original — see 9a for answers)

Each question has a **recommended default** in brackets. Answer "default" to
accept it.

**Gate & enforcement**

1. **Hard gate or warning?** Should Sandbox → Active be *blocked* by incomplete
   "Before registration" items, or only warned (confirm modal: "3 items incomplete,
   activate anyway?")? Can the Founder bypass it? If blocked, should the rest of
   the General tab save go through?
   *[Rec: hard block for Event Managers, Founder can bypass, the other General fields still save.]*
2. **Do `NeedsReview` items block the gate?** Or does only `NotStarted`/`InProgress` block?
   *[Rec: NeedsReview does not block. It nudges, but defaults are legitimately usable.]*

**Acknowledgements & cloning**

3. **Fingerprinting acks:** Is the `value_fingerprint` behavior in §3 worth the
   complexity, or is a simple "acknowledged once, stays acknowledged" enough?
   *[Rec: skip fingerprinting in v1. Acks are simple and permanent per Version.]*
4. **Cloned Versions:** Should *every* cloned value land as `NeedsReview`, or only
   the items most likely to change year to year (dates, senior class, fees, judges,
   invitations), with the rest auto-acknowledged?
   *[Rec: only the year-sensitive items. Re-reviewing ~40 items every year turns the
   checklist into noise for returning managers.]*

**Catalog content**

5. **Granularity:** About 40 items as drafted, or finer (each student-data toggle
   separate), or coarser (one "Requirements" item)?
   *[Rec: ~40 as drafted.]*
6. **"Due by" hints:** Should phases show a due date taken from `version_dates`,
   and turn amber or red as it nears? Which date anchors each phase?
   *[Rec: yes. BeforeInvitations/BeforeRegistration anchor on the earliest Teacher
   start date, and BeforeAuditions on the Adjudication start date.]*
7. **Which of the 9 `VersionDateType`s are required, and under what conditions?**
   (Admin, Teacher, Candidate, FinalTeacherChanges, PostmarkDeadline, Adjudication,
   TabRoom, ParticipationFee, Rehearsal.) What is the `Admin` date for? Is
   PostmarkDeadline only for `application_type=pdf`?
   *[Need your input. I can't infer the required set from the code.]*
8. **Counties:** Are counties required for every event, or only events using
   Co-Registration Managers / county reports?
   *[Need your input.]*
9. **Obligations:** Must every Version publish obligations, or are they optional
   for some organizations?
   *[Rec: optional, as a soft item.]*
10. **Application:** For `application_type=pdf`, does the Version still need a
    published `version_applications` record, or is the PDF generic?
    *[Need your input.]*
11. **Pitch files:** Blocking or soft? Do some events have none?
    *[Rec: soft.]*
12. **Timeslots:** For in-person auditions, does every invited school need a
    timeslot before auditions, or is that optional/managed offline?
    *[Need your input.]*
13. **Rooms for remote auditions:** Do remote events still use Rooms (judges
    assigned to virtual rooms by voice part)? I believe yes, since adjudication is
    room-based, but please confirm.
    *[Rec: rooms are required for both audition types.]*

**Placement & scope**

14. **Namespace:** `app/Readiness/` or `app/Services/Readiness/`?
    *[Rec: `app/Services/Readiness/`, matching existing services.]*
15. **Where does the readiness card live?** Options: (a) top of `VersionEdit`
    above the tabs, (b) per-Version row on `Events\Show` (mini progress bar), or
    (c) both, plus a dedicated checklist page.
    *[Rec: (c). Mini bar on Events Show, card on VersionEdit, plus a full page.]*
16. **Visibility:** Event Managers only, or also Registration Managers (who
    configure much of this)? Should the Founder get a cross-event readiness view on
    `EventDeadlines`?
    *[Rec: Event Manager + Registration Manager, and a Founder column in a later phase.]*
17. **Event-level items across Versions:** Structure items (ensembles, rubric) are
    Event-scoped. Show them on every Version's checklist (computed, not duplicated)?
    *[Rec: yes.]*

**Later phases**

18. **Shape interview trigger:** Only for an Event's *first* Version, or available
    any time ("re-run setup questions")?
    *[Rec: auto-offer on the first Version, re-runnable from the checklist page.]*
19. **Templates:** Are there existing real events you'd be comfortable
    anonymizing into starter templates? Which 2–3 "shapes" are most common among
    prospects (e.g. regional in-person PDF, all-state remote e-application)?
    *[Need your input. Defer until after Phase B.]*
20. **Vocabulary pass:** Do you want to write the plain-language `question()` and
    `why()` copy yourself, or should I draft all ~40 for your edit?
    *[Rec: I draft, you edit in one pass.]*

---

## 10. Build notes (2026-10-02) — Phases A + B

**Files**
- `app/Services/Readiness/` — `ReadinessItem` (value object), `ReadinessContext`
  (everything loaded once per evaluation), `ReadinessResult`, `ReadinessCatalog`
  (the single list of 36 items), `VersionReadiness` (service).
- `app/Enums/ReadinessPhase`, `ReadinessStatus`, `ReadinessReviewState`.
- `version_readiness_reviews` table + `VersionReadinessReview` model, `Version::readinessReviews()`.
- `App\Livewire\Events\VersionReadinessChecklist` at `events/versions/{version}/readiness`
  (`events.versions.readiness`), gated by new `VersionRoleAssignmentService::canManageReadiness()`.
- `<x-readiness-card>` — full card on VersionEdit, compact bar per open Version on Events Show.
- Tests: `tests/Feature/Services/VersionReadinessTest.php` (incl. the §6 coverage test),
  `tests/Feature/Livewire/Events/VersionReadinessChecklistTest.php`; shared fixtures
  (`readinessVersion()`, `readinessConfiguredVersion()`, …) live in `tests/Pest.php`.

**Deviations from the draft**
- Items are closure-based value objects in one catalog file, not one class per item —
  36 tiny classes would have been mostly boilerplate.
- The ack table (§3) became `version_readiness_reviews` with a `state` column
  (`acknowledged` | `review_required`) so cloning can flag year-sensitive items.
- **Saving a VersionEdit tab counts as reviewing that tab's items** (`markSectionReviewed`),
  in addition to the explicit "Looks right" button. Also fired by role assignment and
  mail-to saves (Roles section).
- An optional item acknowledged while empty becomes Done ("we don't use this").
- `Waiting` status dropped for v1 — nothing in the data model records "waiting on someone".
- `version.audition_timeslot` folded into `version.audition_format` (validation already
  requires it for in-person); timeslots excluded (§9a Q12); the live-payments-in-Sandbox
  warning stays a banner, not an item.
- Events Show `activeTab` is now `#[Url(as: 'tab')]` so Structure items can deep-link to
  the Ensembles tab.

**Gate:** `VersionEdit::saveGeneral()` holds Sandbox → Active for non-Founders when any
blocking item through BeforeRegistration is NotStarted/InProgress; other General fields
still save, a `status` error + linked blocker list renders under the Status field.
Existing `VersionEditTest` cases that set Active incidentally were switched to
Sandbox/Inactive.

**Known gaps / follow-ups**
- ~~Registration Managers can view the checklist, but most deep links go to VersionEdit,
  which is Event-Manager-only (403 for them).~~ **Resolved 2026-10-02:** each item has a
  `ReadinessEditor` (EventManager / AuditionEnvironment / RegistrationManager) mirroring its
  destination page's gate. For a viewer who can't edit it, Open (with a lock icon), the
  question link, and Looks right stay visible but open an "Ask an Event Manager" modal listing
  the event's Event Managers; `acknowledge()` enforces the same check server-side. A test
  asserts every item's editor matches its route's gate.
- ~~Rooms / Rubric / Pitch Files / Invitations screens don't call `markSectionReviewed`;
  cloned year-sensitive items there need "Looks right".~~ **Resolved 2026-10-02:** those
  pages plus Co-Registration Managers each own a section (`rooms` → rooms + room judges,
  `rubric`, `pitch_files`, `invitations`, `co_registration`) and call
  `markSectionReviewed` after every successful change (not on refusals, e.g. an
  invitation that can't be removed). A test asserts every reviewable Version item has a
  section.
- Local DB: `2026_09_21_143207_add_audition_cap_per_school_to_versions_table` is unrecorded
  in `migrations` although the column exists (pre-existing; unrelated).

**Spotlight tour (added 2026-10-02):** "Take a tour" on the checklist page, using the same
hand-rolled engine as Events Show (copied per page, no tab switching). 11 steps: the
progress card, one per stage tile, then the Status column (body is a `<template>` legend of
real Flux badges + the Optional rule), Decision, Current, Looks right (falls back to the
Status column when nothing is reviewable), and Open. Anchors exist for both the md:+ table
and the below-md: cards. Auto-starts until dismissed via new
`users.dismissed_readiness_orientation_at`. Fixed the same day: the credentials item now
checks the raw `secret` ciphertext instead of decrypting it (a credential encrypted under
another environment's APP_KEY threw "The MAC is invalid" on Events Show).
