# Event Manager Guide

*A guide to running an audition Event on TheDirectorsRoom.com as an Event Manager.*

## 1. Welcome

As an Event Manager, you own the whole lifecycle of an honors-choir audition program: you set it up, control who can take part, build the audition environment, and — together with whichever Registration Manager, Tab Room Manager, and other role-holders you bring in — you carry it through registration, adjudication, and results.

You'll spend most of your time in two modes. Early in a season you're in **configuration mode**: building or cloning a Version, setting dates and fees, writing the Obligations and Application documents, inviting teachers, and setting up rooms and judges. Later you shift into **oversight mode**: watching registration progress, and — once adjudication starts — tracking scores, applying Ensemble Cut-offs, and closing the audition so teachers can see results. You don't have to do every one of these tasks personally; several pages in this guide are really built for a Registration Manager, Co-Registration Manager, Tab Room Manager, or Web Registration Manager you've delegated to, but as Event Manager you can always reach them too.

## 2. Before you start: the hierarchy

Everything in this guide nests inside one hierarchy. Understanding it up front makes every other page make sense:

- **Organization** — the sponsoring body (for example, a state music association). Owns one or more Events.
- **Event** — the audition program itself, run on a recurring schedule (usually annual). Holds the stable stuff that rarely changes year to year: which Ensembles it produces, how many auditions/ensembles it allows.
- **Version** — one specific year's run of an Event — "the 2027 All-State Chorus," not "All-State Chorus" in general. Holds everything that *does* change year to year: dates, fees, rules, invitations, rooms, and the actual Candidates.
- **Ensemble** — a choir produced as the output of a Version (Mixed Chorus, Treble Choir, etc.). Ensembles belong to the Event, but each Version decides which Voice Parts and grades feed into them that year.
- **Voice Part** — S1, S2, A1, A2, T1, T2, B1, B2, and so on.
- **Student** — a durable person record that belongs to a Teacher's roster and persists across every Event and Version.
- **Candidate** — one Student's enrollment into one specific Version. A new Candidate record is created every year a Student auditions, and it moves through a status lifecycle as registration and adjudication proceed: `eligible → pending → registered`, then on to `withdrew`/`teacher_withdrawn`, or into adjudication outcomes (`adjudicated`, `no_show`, `incomplete`, `accepted`, `not_accepted`), and finally `declined`/`removed` after acceptance.
- **Teacher** — registers Candidates for a Version.

**Roles are granted per-Version**, not app-wide — a person can be Event Manager on one Version and hold no role at all on another. In practice, though, holding **Event Manager** on *any one* Version of an Event gives you full management rights over that whole Event and every one of its Versions — you don't need to be re-added to each new Version separately. The other roles (Registration Manager, Co-Registration Manager, Web Registration Manager, Tab Room Manager, Rehearsal Manager) are scoped to the specific Version they're assigned on.

## 3. The Events list

**Page: Events** (`/events`)

This is your landing page — every Event you're a Founder, an Event Manager on, or hold any other version-scoped role on. Each row shows the Event's name, organization, frequency, audition/ensemble counts, and status badge (**Sandbox**, **Active**, **Inactive**, or **Closed**). Click **Versions** on a row to open that Event's overview page.

If you're currently an assigned judge on an open adjudication window somewhere, you'll also see a teal **Adjudicate** badge letting you jump straight into scoring.

A first-visit guided tour is available via the **Take a tour** button in the top right.

You won't see an "Add" button on this page unless you're a Founder — that's intentional. As an Event Manager, you start a new Event through the separate self-service flow described next, not by editing this list directly.

## 4. Starting a new Event

**Page: Start a New Event** (`/events/new`, reached from your Dashboard)

Any teacher can start a brand-new Event — you don't need to be invited or pre-authorized. Fill in the Event Name, an optional Short Name, the sponsoring Organization, and how often it recurs (Annual, Biannual, Biennial, or Monthly), then click **Create Event**.

Doing this creates the Event *and* its first Version together, both starting in **Sandbox** status, and automatically makes you the Event Manager. Sandbox means the Event/Version isn't public yet — you can configure everything (dates, fees, rules, eligibility, rooms) before anyone else sees it.

## 5. The Event overview page

**Page: Events → [Event Name]** (`/events/{event}`)

This is Event home base, with two tabs: **Versions** and **Ensembles**. A guided tour (the "Take a tour" button) walks through both tabs, including the tab switch itself.

### Versions tab

Lists every Version of this Event, newest first, with a row of color-coded tool buttons under each one (only the buttons you actually have access to appear):

- **Configure** — the big Version setup screen (§6).
- **Invitations** — build the teacher invitation list (§7).
- **Pitch Files** — the audio/video reference library (§8).
- **Co-Registration Managers** — only if you're the Event Manager or that Version's Registration Manager (§9).
- **Scoring Rubric** / **Rooms** — the audition environment, available to Registration/Co-Registration Managers too, not just you (§10–§11).
- **Reports** — the Registration Manager reporting hub, same broadened access (§14).
- **Tab Room** — available to a Tab Room Manager as well as you (§13).
- **Web Registration** — available to a Web Registration Manager as well as you (§12).

**Add Version** starts a new periodic run of this Event. If a prior Version already exists, most fields (dates, fees, requirements, roles, and the rest of its configuration) are **cloned from the latest Version** rather than starting blank — you only have to fill in the new Version's Name, Short Name, and Senior Class Of (the graduating class year for this cycle's seniors). If this is the very first Version, you start from a blank Sandbox default instead.

### Ensembles tab

Lists every Ensemble this Event produces. **Add Ensemble** creates one with a Name, Short Name, and Abbreviation. Each Ensemble card then lets you set:

- **Eligible Grades** — which grade levels (6–12) may audition for this Ensemble.
- **Voice Parts** — which voice parts this Ensemble accepts.

Both save independently with their own **Save Grades** / **Save Voice Parts** buttons.

## 6. Configuring a Version

**Page: Configure** (`/events/versions/{version}/edit`)

This is the main setup screen for a single Version, organized into eight tabs in this order: **General, Dates, Fees, Requirements, Application, Obligations, Payments, Roles.** Only the Event Manager can reach this screen.

### General

The bulk of a Version's identity and rules:

- **Version Name**, **Short Name**, **Senior Class Of** (the graduating class year eligible seniors belong to).
- **Eligible Grades** — grades allowed to register, translated into graduating class years under the hood. Leave empty to allow any grade the Event itself permits.
- **Status** — Sandbox, Active, Inactive, or Closed (see §16 for what each means).
- **Application Type** — `Pdf` (a printed document with ink signatures) or `EApplication` (digital signatures via StudentFolder.info).
- **Audition Type** — In Person or Remote. In-person auditions also ask for an **Audition Slot** duration in minutes.
- **Upload Type** — None, Audio, or Video. Choosing Audio/Video reveals an **Expected Upload Files** panel where you define the generic file labels a Candidate must upload (e.g. "scales," "solo," "quintet"), each with a display order — add one at a time with **Add**, then **Save Upload Files**.
- **Judge Count** — how many judges sit in each audition room.
- **Score Order** — Ascending (lower score is better, "golf") or Descending (higher score is better, "bowling").
- **Cut-off Strategy** — how the Tab Room's Ensemble Cut-offs page will assign Candidates to Ensembles once adjudication is done. This must be set here before that page becomes usable. Four options, described in full in §13:
  - *Single ensemble*
  - *Multiple ensembles, cascading cutoffs*
  - *Multiple ensembles, alternating cutoff*
  - *Multiple ensembles defined by grade*
- **Pitch File Visibility** — Both (Teacher & Candidate), Candidate Only, or Teacher Only.
- **Share Results** — when on, the public (no name/school) combined score PDF is automatically sent to every participating teacher once you release results.
- **Max Registrants** / **Max Upper Voice Registrants** — optional caps.

### Dates

One start/end pair per milestone. Every date type here gates real access somewhere else in the app:

| Date type | What it controls |
|---|---|
| Admin Access | When users with a Version role can access the system at all. No end date. |
| Teacher Access | When teachers can reach the Candidate registration pages. No end date — access also depends on other dates below. |
| Candidate Access | When Candidates can access their registration pages on StudentFolder.info. Some pages (pitch files, payment) stay open past this window on purpose. |
| Final Teacher Changes | After this, a teacher can no longer edit Candidate registration data. No end date. |
| Postmark Deadline | Advisory-only — warns teachers when physical packets must be postmarked by. |
| Adjudication | When named judges can access the adjudication pages, start to end. |
| Tab Room | When named Tab Room Managers can access Tab Room pages. Access also ends automatically once the Version's status is Closed. |
| Participation Fee Payment | The window accepted Candidates can pay their participation fee electronically. |
| Rehearsal | When the Rehearsal Manager can access rehearsal pages — should start no later than Version close and end no later than the actual performance. |

### Fees

Registration, On-Site Registration, Participation, E-Payment Surcharge, and Housing — all entered in dollars, stored in cents. **Registration** and **Participation** are billed as two separate, never-combined charges: registration is owed by any non-withdrawn Candidate any time before the Version closes, while participation is only owed by an *Accepted* Candidate, and only becomes payable once the Version is closed.

### Requirements

- **Membership** — whether a membership card is required, and an optional "valid thru" date.
- **Optional Fields Collected at Registration** — three groups of checkboxes: Candidate fields (Birthday, Shirt Size, Height, Home Address), Emergency Contact fields (Name, Cell, Email), and Other (Teacher Cell). Whatever you check here becomes a required registration milestone for every Candidate — see the poka-yoke checklist described in §17.
- **Eligible Counties** — leave all unchecked to allow any county; check specific ones to restrict both teacher eligibility (§7) and, later, Registration Manager reporting scope.
- **Ensemble Fill Order** — when multiple Ensembles share one candidate pool, this sets which fills first (lower number = higher priority). Only shown once the Event has Ensembles.

### Application

Configures the Candidate Application — the actual document students, parents, and (in Pdf mode) teachers/principals sign. The header, candidate summary table, and fee amounts are always rendered live from real data; only the text bodies below are authored:

- **Schedule** (optional) and **Policies** (optional) — free text, shown only when filled in.
- **Student Endorsement** and **Parent/Guardian Endorsement** — always required.
- **Teacher/Principal Endorsement** — only shown, and only required, when Application Type is `Pdf`.

Each editor has an **Insert token** picker for merge fields (candidate name, voice part, grade, school, fees, etc.), resolved with real data when a Candidate's actual document is generated. Use **Save**, **Publish** (visible to Candidates only once published), **Preview**, or **Print Preview PDF**.

### Obligations

A "Teacher Obligations" document — rules and commitments an invited teacher must agree to before registering students. Same authoring pattern as Application: a **Title** (optional) and rich-text **Obligations Text** with merge-field tokens like `{{versionShortName}}`, plus **Save**, **Publish**/**Unpublish**, and **Preview**.

**This is an iron gate.** A teacher must Accept the published Obligations before they can do anything else on that Version — no dashboard, no candidate pages, nothing. If a teacher **Rejects**, every Candidate they've enrolled who's still in an active pre-adjudication state is automatically bulk-withdrawn, and the teacher is locked out of every Version page until they change their answer. If they later re-accept, those withdrawn Candidates are automatically reinstated and re-checked against the registration checklist — you don't have to manually re-enroll anyone. A **Draft** obligation is completely invisible to teachers; only **Published** ones are ever shown.

### Payments

Two distinct things live on this tab:

- **Vendor Credential** — Square or PayPal setup. This belongs to the **Event**, not just this Version — every Version of the same Event shares one business account. You pick an Environment (Sandbox or Production; which one is actually *used* app-wide is a separate deployment setting, so you can safely prepare or review either without disturbing the other), then Vendor, Account/Client ID, API Secret, and Webhook Signing Key. Secrets are never redisplayed once saved — leave them blank to keep the existing value.
- **Accept Electronic Payment** — this part *is* per-Version: two independent checkboxes for whether teachers and/or students may pay electronically this season. (The on-screen description under the student checkbox is outdated — it still says student payment isn't built yet, but it is; turning it on does enable real student Pay Now checkout on StudentFolder.info.)

### Roles

Assign or remove the five generic version-scoped roles here: **Event Manager, Registration Manager, Web Registration Manager, Tab Room Manager, Rehearsal Manager.** (**Co-Registration Manager** is deliberately *not* assignable here — it has its own screen, §9, because it always comes bundled with a county assignment.) Search for a person by name, pick a role, and **Assign Role**. Only one Registration Manager is allowed per Version at a time — assigning a second is blocked until the first is removed. The Registration Manager's row also has an **Edit mail-to address** button for the physical mailing address printed on the Estimate Form (§18's FAQ has more on this).

## 7. Version Invitations

**Page: Invitations** (`/events/versions/{version}/invitations`)

Controls exactly which teachers may enroll Candidates in this Version. A teacher is *eligible* (computed automatically, nothing to configure directly beyond Requirements' Eligible Counties) if they have an active, verified school **and** either their school's county is on this Version's county list (or the Version has no county restriction at all) **or** they hold any membership — expired or not — in the Event's organization.

The roster table lists every eligible teacher with their school/county, membership expiration, and current status (Eligible, Invited, Obligated, or Participating). Actions:

- **Invite** a single teacher, or **Invite All** to invite everyone currently eligible and not yet invited.
- Uncheck/**Remove** to un-invite, or **Remove All** to bulk-remove everyone still just at "Invited."
- Search and a status filter (click a status tile to filter to it, click again to clear) help on a large roster.

**Guardrail:** once a teacher has moved past plain "Invited" — they've agreed to Obligations, or gone further — you can no longer remove their invitation from here. The system tells you why instead of silently doing nothing.

## 8. Co-Registration Managers

**Page: Co-Registration Managers** (`/events/versions/{version}/co-registration-managers`)

Reachable by you or by that Version's Registration Manager (not by a Co-Registration Manager themselves). Lets a Registration Manager build out their own support team without exposing the rest of Version configuration.

**Add** a manager by searching for their name, then check which of the Version's counties they're responsible for. Counties are mutually exclusive — one county can only be claimed by one Co-Registration Manager on a given Version at a time, and any county already claimed by someone else won't be offered. The same form optionally captures that manager's physical mail-to address (recipient name, organization/school line, address, city/state/zip) — it's fine to leave blank now and fill in later; leaving it blank after it was previously filled in removes the saved address rather than erroring.

## 9. Pitch Files

**Page: Pitch Files** (`/events/versions/{version}/pitch-files`)

An ordered, per-voice-part library of reference audio/video/PDF material (scales, solo excerpts, quintet mixes, etc.) that teachers and/or candidates can access, depending on the Pitch File Visibility setting from §6.

**Add** a file: a Name, the Voice Part it applies to (must be one the Event's Ensembles actually use — or the seeded catch-all "ALL" part, which shows regardless of voice-part filtering), an optional Description, and the file itself (`mp3, wav, m4a, pdf, mp4, mov`, up to 50 MB). Editing lets you replace the file, which deletes the old one from storage. Search, and filter by Voice Part or file name, are available above the table. **Reordering works by drag-and-drop** directly in the table, or via the numeric order fields and **Save Order** as a fallback.

## 10. Rooms

**Page: Rooms** (`/events/versions/{version}/rooms`)

Available to you, or a Registration/Co-Registration Manager on this Version — not Event-Manager-exclusive, because it's genuinely part of running registration/adjudication logistics. Defines the physical (or virtual) rooms candidates are judged in.

**Add** a room: a Name, an optional **Tolerance** (the acceptable point spread between judges' totals — leave blank for "not applied," or set to `0` to require judges' scores to match exactly), which score categories apply in this room, which voice parts are heard here, and an inline judge list (search by name, assign a judge type — Head Judge, Lead Judge, Judge 1–4, Judge Monitor, or Monitor). When you search for a judge, you'll also see their assignment history across every other Version of this same Event, so you can avoid stacking the same person into a high-volume room every year. Reordering is drag-and-drop, same as Pitch Files.

## 11. Scoring Rubric

**Page: Scoring Rubric** (`/events/versions/{version}/scoring-rubric`)

Same broadened access as Rooms. Defines the categories and factors judges score against — a two-level structure (categories contain factors).

By default a Version **inherits** the Event's own rubric — editing here edits the Event-wide default, and every Version that hasn't customized sees the change. Click **Customize for this Version** to fork a private copy just for this Version (the Event default is untouched); once customized, a **Revert to Event default** button appears to discard the Version-specific copy and go back to inheriting. This is all-or-nothing — there's no way to override just one category while inheriting the rest.

Each factor defines Best/Worst score, an Interval (for non-consecutive scoring steps, e.g. 3 → 3, 6, 9, 12), a Multiplier, and an optional per-factor Tolerance (currently informational only — tolerance is actually enforced at the Room level, §10). Ordering is numeric inputs + **Save Order** for both categories and factors within a category — not drag-and-drop at this level.

## 12. Web Registration

**Page: Web Registration** (`/events/versions/{version}/web-registration`)

Available to you or that Version's **Web Registration Manager specifically** — notably *not* the Registration/Co-Registration Manager. Two tabs:

**Impersonate Teacher.** Search for any teacher invited to this Version and log in as them to help with their registration directly. This impersonation is deliberately boxed in: it's locked to this one Version's Registration pages and blocked from that teacher's account/profile settings entirely. A "Return to Web Registration" banner lets you step back out at any time.

**Transfer Students.** Move students (and their current Candidate records) from one invited teacher/school to another — covers a teacher replacement, a cross-school transfer, or a grade-band promotion (e.g. middle school to high school). Pick a **From** school and teacher first — every current student there starts pre-checked, uncheck the ones you don't want to move. Only once From is fully specified (school, teacher, and at least one checked student) does **Transfer To** become selectable. The same teacher can never be offered as both From and To when the same school is picked on both sides, since that would be a no-op that can corrupt roster data. Click **Transfer N Students** to execute.

## 13. Adjudicate (judges only)

**Page: Adjudicate** (`/events/versions/{version}/adjudicate`)

This is the judge-facing scoring screen. You can only open it if you're personally assigned as a judge on a room for this Version (via §10's Rooms screen) — being Event Manager alone does not grant access. If you *are* assigned, you'll see Judge Bios, a Room Progress bar, your assigned candidate roster (identified only by candidate number — auditions are anonymous), a score-entry form per candidate, a Room Scoring table showing every judge's totals once you've entered your own, and a Recordings section for remote auditions. Scores outside the room's tolerance are highlighted but never blocked from saving.

## 14. Tab Room

**Page: Tab Room** (`/events/versions/{version}/tab-room`)

Available to you or that Version's **Tab Room Manager**. This is the hub linking to four sub-pages plus its own Reports section (§15).

### Add/Edit Scores

Search a Candidate by id or last name, then enter or correct any judge's scores on their behalf — useful for a paper backup entry or fixing a mistake. Every score you enter here is flagged as **overridden**, distinguishing a Tab Room correction from the judge's own original entry. **Changing a candidate's voice part on this page is destructive**: it deletes every score already recorded for them, since scores are tied to a specific voice part's rubric. You'll get an explicit warning before it happens.

### Adjudication Tracking

Read-only progress dashboards: an overall completion bar, a Red/Amber/Green status per room (red = not started or has an error, amber = in progress, green = every candidate completed), and a per-candidate badge grid with a judge-by-judge score breakdown on hover. Auto-refreshes roughly every 10 seconds while open.

### Ensemble Cutoffs

Requires a Cut-off Strategy to already be set on the General tab (§6) — otherwise this page won't open. Each Voice Part shows a full ranked list of candidates, best-to-worst. How you apply a cutoff depends on the strategy:

- **Single ensemble** / **Multiple ensembles, alternating cutoff** — one click on a ranked score immediately resolves the whole Voice Part.
- **Multiple ensembles, cascading cutoffs** / **Multiple ensembles defined by grade** — you enter a separate cutoff score per eligible Ensemble in turn, then explicitly **finalize** the Voice Part once every Ensemble's cutoff has been applied; anyone left over is marked Not Accepted at that point.

Candidates tied at the exact same score always move together — ties are never split. Once a Voice Part is fully decided it collapses into a compact "Resolved" summary; **Reopen** just re-expands the detail view (it never changes any decision on its own) — re-clicking a different cutoff score re-decides the whole Voice Part in place, including moving previously-accepted candidates back out if they no longer qualify. Nothing here is destructive in the sense of losing data: you can re-run a cutoff as many times as you need before closing the audition. The page also shows a per-Ensemble pie-chart breakdown by voice part, and a manually-editable History panel for entering prior seasons' counts (useful the first year or two after adopting this system, before it has its own history to show).

### Close Audition

The final step. **Close** sets the Version's status to Closed and — separately — stamps a `results_released_at` timestamp, which is the actual switch that makes results visible to teachers. These are deliberately two different things: closing the Version for unrelated administrative reasons via the Configure screen's General tab does *not* silently release results as a side effect; only this page's Close button does that. An optional **email participating teachers** checkbox sends a results-available notification, signed with your own name. **Reopen** clears the results-visibility flag (hiding results again during a correction window) and puts the Version back to Active — it never undoes any candidate's accept/reject decision.

## 15. Tab Room Reports

**Page: Tab Room → Reports** (`/events/versions/{version}/tab-room/reports`)

Five reports, available to you or the Tab Room Manager:

- **Audition Scores** — one Voice Part's full per-judge, per-factor score table, best to worst. PDF only.
- **Combined Audition Scores (Confidential)** — every Voice Part in one Ensemble (or "All" for every Ensemble at once) with full candidate identity shown. PDF/CSV; the "All Ensembles" export is large enough that it's generated in the background and emailed to you rather than downloaded immediately.
- **Combined Audition Scores (Public)** — the same report with student/school/teacher identity stripped out, showing only candidate numbers. This is the version that gets auto-shared with teachers when "Share Results" (§6) is turned on.
- **Ensemble Participation** — every accepted member of one Ensemble with contact info, for rehearsal/logistics planning. PDF/CSV.
- **Student Seniority** — for each currently accepted member of one Ensemble, whether they were also accepted in that same Ensemble's prior Versions (a cross-Version look-back), grouped by graduating class year. PDF/CSV.

## 16. Registration Manager reports

**Page: Reports** (`/events/versions/{version}/reports`)

Nine reports, available to you or a Registration/Co-Registration Manager on this Version. A Registration Manager (or you) sees every county with an optional filter; a Co-Registration Manager is automatically locked to only their assigned counties, with no filter shown.

1. **Obligated Teachers** — every teacher who's accepted this Version's Obligations. PDF.
2. **Participating Teachers** — teachers with at least one Registered candidate. PDF.
3. **Participating Schools** — one row per school/teacher pair with a Registered candidate: fees due/paid/balance, a **packet received** checkbox, and a **Payment** button for manually recording a check/PO/cash payment. Checking "packet received" doesn't email anyone by itself — click **Send Confirmations** to batch-email every teacher whose packet is marked received but not yet confirmed. Unchecking a packet afterward does *not* un-send an already-sent confirmation.
4. **Payment Reconciliation** — every payment transaction (electronic or manually recorded) for the Version, with an allocation status of fully allocated, partially allocated, or **Needs Reconciliation**. A manually-recorded lump-sum payment lands here unallocated until you open it and assign specific dollar amounts to specific candidates — until allocated, it doesn't count toward any candidate's balance.
5. **Participating Candidates** — the full Registered roster with an Edit modal (name, voice part, home/cell phone, emergency contact) and a Remove action that withdraws a candidate (Withdrew or Teacher Withdrawn).
6. **Participation by County** — one row per county: obligated-teacher count, participating-teacher count, candidate count, and the responsible manager's name. Counties with zero activity still show up.
7. **Candidate Counts** — one row per school/teacher with a count column per voice part plus a total, and a Version-wide summary header. PDF/CSV.
8. **Adjudication Backup** — real navigation and export buttons exist, but the actual content is placeholder ("no in-person audition scheduled") because room-to-candidate assignment isn't part of this system yet — don't mistake this for a bug.
9. **Registration Cards** — same situation: real filters and a working print link, but placeholder PDF content for the same reason as above.

## 17. Registration, in brief

You won't personally do most registration-phase work — that's the teacher's and (once StudentFolder.info is involved) the candidate's job — but it's worth knowing what they're up against, since your Requirements tab settings (§6) directly control it. A Candidate only reaches **Registered** once every milestone your Version requires is complete: emergency contact info, home address, candidate cell/email, application signatures, and — if remote auditions are configured — every required upload approved by the teacher. Anything partially done sits at **pending**. Teachers see a checklist explaining exactly what's still missing, so "why isn't this student Registered?" should always have a visible answer on their end.

## 18. Common questions and gotchas

- **A teacher rejected Obligations — what happens to their students?** Every Candidate they'd enrolled in an active pre-adjudication state is automatically withdrawn. If the teacher later accepts, those same candidates are automatically reinstated and re-checked against the registration checklist — you don't need to do anything manually on either side.

- **I can't remove an invitation.** Once a teacher has moved past "Invited" (they've accepted Obligations or gone further), the Invitations page blocks removal and tells you why instead of silently failing.

- **Draft vs. Published, for Obligations and the Application.** A document in Draft status is completely invisible to teachers/candidates — publishing is a distinct, explicit action, not something that happens automatically when you save.

- **Ensemble Cut-offs won't open.** You need a Cut-off Strategy chosen on the Version's Configure → General tab first — it's a required prerequisite, not optional configuration.

- **Closing a Version via Configure vs. via Close Audition — what's the difference?** Setting Status to Closed on the Configure screen's General tab changes the Version's status alone. Only the Tab Room's dedicated **Close Audition** page also stamps the separate flag that actually makes results visible to teachers. This split is intentional, so closing a Version for an unrelated administrative reason never silently exposes results early.

- **Reopening after Close doesn't undo acceptances.** Reopen only hides results from teachers again (for a correction window) and flips the Version back to Active — it never reverses any candidate's accept/not-accept decision. You make a correction via Ensemble Cutoffs or Add/Edit Scores, then Close again.

- **Payment vendor credentials are per-Event, not per-Version.** Every Version of the same Event shares one Square or PayPal business account. If a manual payment you recorded isn't showing up against a candidate's balance, check whether it's sitting unallocated on the Payment Reconciliation report — a lump-sum payment doesn't count toward anyone's balance until you allocate it to specific candidates.

- **Changing a candidate's voice part in Tab Room deletes their scores.** Add/Edit Scores warns you before this happens, but it's worth knowing in advance: since scores are tied to a voice part's specific rubric, a voice-part change has no scores left to keep.

- **A Registration Manager can only be assigned once per Version.** Assigning a second is blocked until the first is removed — this keeps the Estimate Form's mail-to fallback address unambiguous.
