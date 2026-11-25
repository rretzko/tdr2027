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

If you're currently an assigned judge on an open adjudication window somewhere, you'll also see a teal **Adjudicate** button letting you jump straight into scoring.

A first-visit guided tour is available via the **Take a tour** button in the top right.

You won't see an "Add" button on this page unless you're a Founder — that's intentional. As an Event Manager, you start a new Event through the separate self-service flow described next, not by editing this list directly.

## 4. Starting a new Event

**Page: Start a New Event** (`/events/new`, reached from your Dashboard)

Any teacher can start a brand-new Event — you don't need to be invited or pre-authorized. Fill in the Event Name, an optional Short Name, the sponsoring Organization, and how often it recurs (Annual, Biannual, Biennial, or Monthly), then click **Create Event**. You can also add other Event Managers here.

Doing this creates the Event *and* its first Version together, both starting in **Sandbox** status, and automatically makes you the Event Manager. Sandbox means the Event/Version isn't public yet — you can configure everything (dates, fees, rules, eligibility, rooms) before anyone else sees it.

### Setup questions and the Setup checklist

A new Event's first Version opens a short set of **setup questions**. Your answers fill in settings and trim the checklist to what applies to you. Skip anything you're unsure of; you can come back any time.

The **Setup checklist** lists every decision the Version needs, in the order you'll need them. Its progress card appears with each Version on the Event overview page (§5). Items update on their own as you fill things in, and **Looks right** confirms a setting you've checked and want to keep. After cloning, settings carried over are marked for review. The Version's Registration Manager can open the checklist too.

The checklist also controls activation: a Version can't move from Sandbox to Active until its before-registration items are finished. If any remain, your other changes are saved and you'll see what's left. Activating a Version also makes its Event Active.

## 5. The Event overview page

**Page: Events → [Event Name]** (`/events/{event}`)

This is Event home base, with two tabs: **Versions** and **Ensembles**. A guided tour (the "Take a tour" button) walks through both tabs, including the tab switch itself.

### Versions tab

Lists every Version of this Event, newest first, with a row of color-coded tool buttons under each one (only the buttons you actually have access to appear):

- **Configure** — the big Version setup screen (§6).
- **Invitations** — build the teacher invitation list (§7).
- **Pitch Files** — the audio/video reference library (§9).
- **Co-Registration Managers** — only if you're the Event Manager or that Version's Registration Manager (§8).
- **Scoring Rubric** / **Rooms** — the audition environment, available to Registration/Co-Registration Managers too, not just you (§10–§11).
- **Reports** — the Registration Manager reporting hub, same broadened access (§16).
- **Tab Room** — available to a Tab Room Manager as well as you (§14).
- **Web Registration** — available to a Web Registration Manager as well as you (§12).
- **Scorecard** — registration, adjudication, and selection figures for this Version, for Event Managers only (§13–§14).

**Add Version** starts a new periodic run of this Event. If a prior Version exists, most of its configuration is **copied from the latest Version**: settings, dates, fees, requirements, documents, pitch files, Rooms, and invitations. You only fill in the new Version's Name, Short Name, and Senior Class Of. It starts in Sandbox, and the Obligations and Application start as drafts. Each date moves forward one year, at the same time of day, so review them against the new calendar. The latest Version's Event Managers carry over, and you become one too if you weren't already.

**Not copied:** every other role, judges, and candidates. Assign roles in Configure → Roles (§6), Co-Registration Managers on their own screen (§8), and judges in Rooms (§10). Rooms keep only the score categories that belong to the Event's default rubric; reassign any that were customized for the old Version. Cut-off Strategy, Share Results, and Judge check-ins on the General tab aren't copied either, so set them again.

### Ensembles tab

Lists every Ensemble this Event produces. **Add Ensemble** creates one with a Name, Short Name, and Abbreviation. Each Ensemble card then lets you set:

- **Eligible Grades** — which grade levels (4–12) may audition for this Ensemble.
- **Voice Parts** — which voice parts this Ensemble accepts.

Both save independently with their own **Save Grades** / **Save Voice Parts** buttons.

## 6. Configuring a Version

**Page: Configure** (`/events/versions/{version}/edit`)

This is the main setup screen for a single Version, organized into eight tabs in this order: **General, Dates, Fees, Requirements, Application, Obligations, Payments, Roles.** Only the Event Manager can reach this screen.

### General

The bulk of a Version's identity and rules:

- **Version Name**, **Short Name**, **Senior Class Of** (the graduating class year eligible seniors belong to).
- **Eligible Grades** — grades allowed to register, translated into graduating class years under the hood. Leave empty to allow any grade the Event itself permits.
- **Status** — Sandbox, Active, Inactive, or Closed. Activating requires a finished Setup checklist (§4). To end an audition, use Close Audition (§14) rather than setting Closed here.
- **Application Type** — `Pdf` (a printed document with ink signatures) or `EApplication` (digital signatures via StudentFolder.info).
- **Audition Type** — In Person or Remote. In-person auditions also ask for an **Audition Slot** duration in minutes.
- **Upload Type** — None, Audio, or Video. Choosing Audio/Video reveals an **Expected Upload Files** panel where you define the generic file labels a Candidate must upload (e.g. "scales," "solo," "quintet"), each with a display order — add one at a time with **Add**, then **Save Upload Files**.
- **Judge Count** — how many judges sit in each audition room.
- **Score Order** — Ascending (lower score is better, "golf") or Descending (higher score is better, "bowling").
- **Cut-off Strategy** — how the Tab Room's Ensemble Cut-offs page will assign Candidates to Ensembles once adjudication is done. This must be set here before that page becomes usable. Four options, described in full in §14:
  - *Single ensemble*
  - *Multiple ensembles, cascading cutoffs*
  - *Multiple ensembles, alternating cutoff*
  - *Multiple ensembles defined by grade*
- **Pitch File Visibility** — Both (Teacher & Candidate), Candidate Only, or Teacher Only.
- **Share Results** — when on, the public (no name/school) combined score PDF is automatically sent to every participating teacher once you release results.
- **Judge check-ins** — when on, judges get a short thank-you and optional questions as they work through their Room (§13).
- **Audition Cap Per School** — optional. Limits one school's active candidates per audition group; Ensembles that share a Voice Part count as one group.
- **Max Registrants** / **Max Upper Voice Registrants** — optional. These limit how many registered students are listed on a teacher's Estimate Form; they don't stop anyone from registering.

### Dates

One entry per milestone; some have an end date. Some dates control access, and others are for reference:

| Date type | What it controls |
|---|---|
| Admin Access | Reference only; people with a Version role aren't restricted by it. |
| Teacher Access | When the Version opens on teachers' Registrations page. No end date. You're emailed if it's about to open, or just has, and the Version isn't Active. |
| Candidate Access | When students can edit their registration on StudentFolder.info. Pitch files and student registration payments stay open until Final Teacher Changes (payments stop sooner if adjudication starts). |
| Final Teacher Changes | The registration cut-off. After it, Eligible and Pending candidates can't become Registered through teachers' changes, and teachers can't change voice parts or recordings. If it isn't set, the Postmark Deadline is used. No end date. |
| Postmark Deadline | When mailed materials must be postmarked. It also serves as the registration cut-off when Final Teacher Changes isn't set. |
| Adjudication | When named judges can access the adjudication pages, start to end. |
| Tab Room | Reference only. Tab Room Managers can open the Tab Room at any time, but scores and cut-offs are locked while the Version is Closed. |
| Participation Fee Payment | Reference only. Accepted candidates can pay once the Version is Closed. |
| Rehearsal | Reference only. |

### Fees

Registration, On-Site Registration, Participation, E-Payment Surcharge, and Housing — all entered in dollars, stored in cents. **Registration** and **Participation** are billed as two separate, never-combined charges: registration is owed by any non-withdrawn Candidate any time before the Version closes, while participation is only owed by an *Accepted* Candidate, and only becomes payable once the Version is closed. To excuse one student from a fee, use a fee waiver (§16). The E-Payment Surcharge is added once to each online checkout. The On-Site Registration fee is shown on the Application but isn't charged by the site.

### Requirements

- **Membership** — whether a membership card is required, and an optional "valid thru" date.
- **Mail** — whether teachers must mail physical materials to complete registration.
- **Optional Fields Collected at Registration** — three groups of checkboxes: Candidate fields (Birthday, Shirt Size, Height, Home Address), Emergency Contact fields (Name, Cell, Email), and Other (Teacher Cell). Whatever you check here becomes a required registration milestone for every Candidate (§17), with two exceptions: Teacher Cell isn't a milestone, and Emergency Contact Cell and Email count only when Name is also checked.
- **Eligible Counties** — leave all unchecked to allow any county; check specific ones to restrict both teacher eligibility (§7) and, later, Registration Manager reporting scope.
- **Ensemble Fill Order** — when multiple Ensembles share one candidate pool, this sets which fills first (lower number = higher priority). Only shown once the Event has Ensembles.

### Application

Configures the Candidate Application — the actual document students, parents, and (in Pdf mode) teachers/principals sign. The header, candidate summary table, and fee amounts are always rendered live from real data; only the text bodies below are authored:

- **Schedule** (optional) and **Policies** (optional) — free text, shown only when filled in.
- **Student Endorsement** and **Parent/Guardian Endorsement** — always required.
- **Teacher/Principal Endorsement** — only shown, and only required, when Application Type is `Pdf`.

Each editor has an **Insert token** picker for merge fields (candidate name, voice part, grade, school, fees, etc.), resolved with real data when a Candidate's actual document is generated. Use **Save**, **Publish** (visible to Candidates only once published), **Unpublish**, **Preview**, or **Print Preview PDF**.

### Obligations

A "Teacher Obligations" document — rules and commitments an invited teacher must agree to before registering students. Same authoring pattern as Application: a **Title** (optional) and rich-text **Obligations Text** with merge-field tokens like `{{versionShortName}}`, plus **Save**, **Publish**/**Unpublish**, and **Preview**.

**This is an iron gate.** A teacher must Accept the published Obligations before they can do anything else on that Version — no dashboard, no candidate pages, nothing. If a teacher **Rejects**, every Candidate they've enrolled who's still in an active pre-adjudication state is automatically bulk-withdrawn, and the teacher is locked out of every Version page until they change their answer. If they later re-accept, those withdrawn Candidates are automatically reinstated and re-checked against the registration checklist — you don't have to manually re-enroll anyone. A **Draft** obligation is completely invisible to teachers; only **Published** ones are ever shown.

### Payments

Two distinct things live on this tab:

- **Vendor Credential** — Square or PayPal setup. This belongs to the **Event**, not just this Version — every Version of the same Event shares one business account. You pick an Environment (Sandbox or Production; which one is actually *used* app-wide is a separate deployment setting, so you can safely prepare or review either without disturbing the other), then Vendor, Account/Client ID, API Secret, and Webhook Signing Key. Secrets are never redisplayed once saved — leave them blank to keep the existing value.
- **Accept Electronic Payment** — this part *is* per-Version. Choose whether teachers may pay their balance electronically, whether payment is **online only, with no checks** (you can still record an exception), and whether teachers may let their students pay online. Each teacher then decides for their own students, and student payments are credited toward that teacher's balance.

### Roles

Assign or remove the five generic version-scoped roles here: **Event Manager, Registration Manager, Web Registration Manager, Tab Room Manager, Rehearsal Manager.** (**Co-Registration Manager** is deliberately *not* assignable here — it has its own screen, §8, because it always comes bundled with a county assignment.) Search for a person by name, pick a role, and **Assign Role**. Assigning a role to a teacher also invites them to the Version. Only one Registration Manager is allowed per Version at a time — assigning a second is blocked until the first is removed. The Registration Manager's row also has an **Edit mail-to address** button for the physical mailing address printed on the Estimate Form (§18's FAQ has more on this).

## 7. Version Invitations

**Page: Invitations** (`/events/versions/{version}/invitations`)

Controls exactly which teachers may enroll Candidates in this Version. A teacher is *eligible* (computed automatically, nothing to configure directly beyond Requirements' Eligible Counties) if they have an active, verified school **and** that school's county is on this Version's county list. If the Version has no county restriction at all, every teacher with an active, verified school is eligible. Organization membership does not make a teacher eligible on its own.

The roster table lists every eligible teacher with their school/county, membership expiration (shown for reference only), and current status (Eligible, Invited, Obligated, or Participating). Actions:

- Check a teacher's box to invite them, or use **Invite All** to invite everyone currently eligible and not yet invited.
- Uncheck the box to un-invite, or use **Remove All** to bulk-remove everyone still just at "Invited."
- **Upload Directors** adds many directors at once from a CSV file. Download the template, fill in one row per director, and review the preview before anything is saved. Directors already in the system are matched by email and their details aren't changed; new ones get an account. Everyone is invited to this Version.
- Search and a status filter (click a status tile to filter to it, click again to clear) help on a large roster.

**Guardrail:** once a teacher has moved past plain "Invited" — they've agreed to Obligations, or gone further — you can no longer remove their invitation from here. The system tells you why instead of silently doing nothing.

## 8. Co-Registration Managers

**Page: Co-Registration Managers** (`/events/versions/{version}/co-registration-managers`)

Reachable by you or by that Version's Registration Manager (not by a Co-Registration Manager themselves). Lets a Registration Manager build out their own support team without exposing the rest of Version configuration.

Set the Version's counties first (Configure → Requirements, §6); without them there's nothing to assign. **Assign Co-Registration Manager** by searching for their name, then check which of the Version's counties they're responsible for. Counties are mutually exclusive — one county can only be claimed by one Co-Registration Manager on a given Version at a time, and any county already claimed by someone else won't be offered. The same form optionally captures that manager's physical mail-to address (recipient name, organization/school line, address, city/state/zip) — it's fine to leave blank now and fill in later; leaving it blank after it was previously filled in removes the saved address rather than erroring.

## 9. Pitch Files

**Page: Pitch Files** (`/events/versions/{version}/pitch-files`)

An ordered, per-voice-part library of reference audio/video/PDF material (scales, solo excerpts, quintet mixes, etc.) that teachers and/or candidates can access, depending on the Pitch File Visibility setting from §6.

**Add** a file: a Name, the Voice Part it applies to (must be one the Event's Ensembles actually use — or the seeded catch-all "ALL" part, which shows regardless of voice-part filtering), an optional Description, and the file itself (`mp3, wav, m4a, pdf, mp4, mov`, up to 50 MB). To give a PDF a **Read** link instead of an audio player, name it exactly "pdf". Editing lets you replace the file. Add Version carries pitch files over, and replacing or removing one in this Version doesn't affect the others. Search, and filter by Voice Part or file name, are available above the table. **Reordering works by drag-and-drop** directly in the table, or via the numeric order fields and **Save Order** as a fallback.

## 10. Rooms

**Page: Rooms** (`/events/versions/{version}/rooms`)

Available to you, or a Registration/Co-Registration Manager on this Version — not Event-Manager-exclusive, because it's genuinely part of running registration/adjudication logistics. Defines the physical (or virtual) rooms candidates are judged in.

**Add** a room: a Name, an optional **Tolerance** (the acceptable point spread between judges' totals — leave blank for "not applied," or set to `0` to require judges' scores to match exactly), which score categories apply in this room, which voice parts are heard here, and an inline judge list (search by name, assign a judge type — Head Judge, Lead Judge, Judge 1–4, Judge Monitor, or Monitor). When you choose a judge, you'll also see their assignment history across every other Version of this same Event, so you can avoid stacking the same person into a high-volume room every year. Reordering is drag-and-drop, same as Pitch Files. Each Room shows its candidate count, and **Print roster** gives you a PDF of every Room.

Some things to know about judges:

- **They need an account.** The search finds any TheDirectorsRoom.com account that isn't a student's.
- **Assign each judge to one Room per Version.** The Adjudicate page opens only one (§13).
- **Everyone listed must score.** A candidate is Completed only when every person in the Room's judge list has scored them, including a Judge Monitor or Monitor. Don't list anyone who won't be scoring.
- **Removing a judge or Room deletes saved scores.** You'll see what would be lost first; **Keep scores** backs out. To replace a judge who has scores, use **Add judge**; their row can't be reassigned.

## 11. Scoring Rubric

**Page: Scoring Rubric** (`/events/versions/{version}/scoring-rubric`)

Same broadened access as Rooms. Defines the categories and factors judges score against — a two-level structure (categories contain factors).

By default a Version **inherits** the Event's own rubric — editing here edits the Event-wide default, and every Version that hasn't customized sees the change. Click **Customize for this Version** to fork a private copy just for this Version (the Event default is untouched); once customized, a **Revert to Event default** button appears to discard the Version-specific copy and go back to inheriting. This is all-or-nothing — there's no way to override just one category while inheriting the rest.

Each factor defines Best/Worst score, an Interval (for non-consecutive scoring steps, e.g. 3 → 3, 6, 9, 12), a Multiplier, and an optional per-factor Tolerance (currently informational only — tolerance is actually enforced at the Room level, §10). Ordering is numeric inputs + **Save Order** for both categories and factors within a category — not drag-and-drop at this level.

## 12. Web Registration

**Page: Web Registration** (`/events/versions/{version}/web-registration`)

The first three tabs are for you and that Version's **Web Registration Manager**. A Registration or Co-Registration Manager sees only Registration History and Voice Part Audit; a Co-Registration Manager sees only their own counties.

**Impersonate Teacher.** Search for any teacher invited to this Version and log in as them to help with their registration directly. This impersonation is deliberately boxed in: it's locked to this one Version's Registration pages and blocked from that teacher's account/profile settings entirely. A "Return to Web Registration" banner lets you step back out at any time.

**Preview Student.** Search this Version's students and see one student's StudentFolder.info pages exactly as they do. You can fill in missing profile details on their behalf. Signing, uploading, paying, and changing schools stay disabled.

**Transfer Students.** Move students (and their current Candidate records) from one invited teacher/school to another — covers a teacher replacement, a cross-school transfer, or a grade-band promotion (e.g. middle school to high school). Pick a **From** school and teacher first — every current student there starts pre-checked, uncheck the ones you don't want to move. Only once From is fully specified (school, teacher, and at least one checked student) does **Transfer To** become selectable. The same teacher can never be offered as both From and To when the same school is picked on both sides, since that would be a no-op that can corrupt roster data. An inactive teacher can be chosen under From, so you can move the students of someone who has left. Click **Transfer N Students** to execute.

**Registration History.** Search for a teacher or student to see what happened and when in this Version, who did it, and what's still incomplete. Times are Eastern. **Download .txt** saves a copy to send. Two limits: actions taken while impersonating someone are recorded under that person's account, and some earlier states, such as replaced recordings and removed signatures, aren't kept. Scores and contact details never appear, and outcomes stay hidden until results are released.

**Voice Part Audit.** Listen to candidates' approved recordings and correct a voice part that doesn't match. Only voice parts offered for the student's grade can be chosen, and **Grade mismatch only** finds candidates whose voice part isn't. Filter by status, voice part, or name; only active candidates are listed at first. Each change asks whether the recordings match the new part. **Yes, they match** keeps the recordings and their approvals. **No, remove them** permanently deletes every recording for that candidate, and after the deadline for teacher changes they can't be uploaded again. If the candidate already has scores, use Change Voice Part in Add/Edit Scores instead (§14).

## 13. Adjudication and your judges

**Page: Adjudicate** (`/events/versions/{version}/adjudicate`)

This is the judge-facing scoring screen. You can only open it if you're personally assigned as a judge in a Room for this Version (§10) — being Event Manager alone does not grant access.

The **Adjudicator Guide**, in the User Guides section of the sidebar, walks judges through this page and includes a picture of it. Directors can open it; accounts with a student record can't, so send those judges the PDF. The rest of this section covers your side.

### Before the window opens

The site doesn't email judges when you assign them. Tell each judge their Room and dates, and point them to the Adjudicator Guide. A judge sees the teal **Adjudicate** button on the Events page only when all of these are true:

- They're assigned to a Room in this Version.
- The Version's Status is Active.
- Today falls within the Adjudication date (§6).
- Their account doesn't belong to a currently enrolled student.

Check that every Room has its score categories, voice parts, and judges. A Room without score categories shows judges "This room has no scoring rubric," and they can't score anything.

### What judges see

- **Judges** — everyone in the Room, with role, school, email address, and cell phone when on file.
- **Room Progress** — how many candidates are Completed, Pending, Non-adjudicated, or in Error.
- **Candidates** — candidate numbers only, with no names or schools, grouped by voice part. Red means no scores yet, yellow some, green every judge has scored, and black more scores than the rubric calls for. A check mark means that judge has finished the candidate; a red asterisk means out of tolerance.
- **Recordings** — remote auditions only: the candidate's approved recordings that match the Room's score categories.
- **Score** — one score for every factor. A partial score sheet can't be saved.
- **Room Scores** — every judge's scores for a candidate. It stays hidden until the judge has saved their own.

Judges can change their scores as often as they like while the window is open. There's no final Submit button; scores count as soon as they're saved.

### Tolerance

Tolerance compares each judge's total across the Room's whole rubric, using scores as entered, before multipliers. The totals on the Adjudicate page include multipliers, so the two may not appear to match. Being out of tolerance never blocks a save. Tell your judges whether and how you expect them to adjust.

### Judge check-ins

When **Judge check-ins** is on (Configure → General, §6), judges get a short thank-you and up to three optional questions after a save at one-third, two-thirds, and one candidate to go. Small Rooms may see fewer. Answers appear on the Scorecard once three judges have answered, combined and never by judge.

### Following progress

Open **Scorecard** from the Event overview page (§5) and choose the **Adjudication** tab. It refreshes every two minutes and shows, among other things:

- Overall progress, and whether judges will finish on time at their recent pace.
- **Judges who may need a nudge** — not started, barely started, or idle, with email links.
- **Judges scoring their own students** — judges with students they teach, or from a school where they teach, in their Room. The Adjudicate page can't warn judges about this, so check it early.
- **Rooms** — each judge's progress and pace. Where playback is tracked, you'll also see scores saved before a recording finished.
- **Judge check-ins** — response rate and combined answers.

Your Tab Room Manager can't open the Scorecard; they follow progress on Adjudication Tracking (§14).

### When a judge needs help

- **Can't reach the page** — check the four conditions above.
- **Scored the wrong candidate** — a judge can save correct scores over wrong ones but can't clear a score sheet.
- **Lost internet** — saved scores are safe. The judge can give you the rest on paper, and the Tab Room can enter them with Add/Edit Scores (§14).
- **The window has closed** — extend the Adjudication date, or use Add/Edit Scores.
- **A recording is missing or unclear** — only approved recordings appear, and each must be uploaded under a label that matches one of the Room's score categories.
- **Assigned to two Rooms** — the Adjudicate page opens only one Room per judge in each Version. Assign each judge to one Room.

## 14. Tab Room

**Page: Tab Room** (`/events/versions/{version}/tab-room`)

Available to you or that Version's **Tab Room Manager**. This is the hub linking to Add/Edit Scores, Adjudication Tracking, Ensemble Cut-offs, and Close Audition, plus its own Reports section (§15).

### Add/Edit Scores

Search a Candidate by id or last name, then enter or correct any judge's scores on their behalf — useful for a paper backup entry or fixing a mistake. Every score you enter here is flagged as **overridden**, distinguishing a Tab Room correction from the judge's own original entry. **Changing a candidate's voice part on this page is destructive**: it deletes every score already recorded for them, since scores are tied to a specific voice part's rubric. You'll get an explicit warning before it happens. If the candidate has recordings, you'll also be asked whether they match the new voice part; answering no permanently deletes them. For remote auditions you can play the same recordings the judge hears. If Ensemble Cut-offs has already resolved the candidate, a warning reminds you to re-evaluate the cut-offs after saving. Scores can't be changed once the Version is Closed; reopen it first.

### Adjudication Tracking

Read-only progress dashboards: an overall completion bar, a Red/Amber/Green status per room (red = not started or has an error, amber = in progress, green = every candidate completed), and a per-candidate badge grid with a judge-by-judge score breakdown on hover. Auto-refreshes roughly every 10 seconds while open.

### Ensemble Cut-offs

Requires a Cut-off Strategy on the General tab (§6) — otherwise this page won't open. Every Voice Part appears side by side, with its scores ranked best to worst. How you apply a cut-off depends on the strategy:

- **Single ensemble** / **Multiple ensembles, alternating cutoff** — click a score and confirm. That resolves the whole Voice Part: everyone at or better than the score is accepted, and everyone else is Not Accepted. Candidates with no scores become No Show and partially scored ones become Incomplete, so make sure judging is finished first.
- **Multiple ensembles, cascading cutoffs** / **Multiple ensembles defined by grade** — click a score to set one Ensemble's line; it applies at once. Click inside an Ensemble's band to move its line, or below every line to set the next Ensemble's. **Reset** clears every line in a Voice Part and returns its candidates to undecided. Everyone below the lines stays undecided until you finalize.

**Finalize — reject remaining** appears for the two strategies that set a line per Ensemble. It runs once for the **whole Version**, not for each Voice Part. It marks everyone below the lines Not Accepted and anyone with no scores No Show, after showing you how many of each. It won't run while any candidate is partially scored, over-scored, or out of tolerance, or while an Ensemble with candidates still available has no line; the page lists what to fix first.

Tied candidates always move together — ties are never split. A decided Voice Part collapses to a "Resolved" summary; **Reopen** only expands it again and changes nothing. You can re-run cut-offs as often as you need before closing, and clicking a different score re-decides that Voice Part, including moving accepted candidates back out. If scores change after a cut-off, re-apply it. Cut-offs are locked while the Version is Closed.

The page also shows accepted counts, a pie chart per Ensemble, and a History panel. Past seasons can be typed in; the current season is recorded for you when you close.

### Close Audition

The final step. **Close Audition** marks the Version Closed and makes results visible to teachers. These are two different things: setting Status to Closed on the General tab does *not* release results; only this page does. An optional checkbox **emails participating teachers** that results are available, signed with your name.

When a Cut-off Strategy is set, closing is refused while any Registered candidate is still undecided. The page lists them by voice part and tells you whether to finalize or apply a cut-off first.

Closing also:

- Records this season's accepted counts in the Ensemble Cut-offs History panel.
- Captures the Version's analytics, which adds **Download analytics report** to the Scorecard.
- Builds the Ensemble Participants CSVs (§16, Downloads).
- Prepares the public combined scores for teachers, if Share Results is on.
- Schedules the platform-usage invoice, emailed to this Version's Event Managers 24 hours later and sent only once.

**Reopen** hides results again for corrections, puts the Version back to Active, and cancels the invoice if it hasn't been sent; closing again restarts the 24 hours. It never undoes an accept or not-accept decision. Closing again refreshes the analytics and the CSVs.

### After the audition: the Scorecard

The Scorecard's **Registration** tab shows current figures, weekly trends, when students registered, and which schools returned, along with students still Pending after the Postmark Deadline. Event Managers are emailed its headline figures each Monday until adjudication begins. The **Selection** tab fills in once cut-offs are applied: outcomes, how close each cut-off was, and how scores were distributed. Once the analytics are captured, **Download analytics report** gives you a PDF of all three tabs' findings.

## 15. Tab Room Reports

**Page: Tab Room → Reports** (`/events/versions/{version}/tab-room/reports`)

Five reports, available to you or the Tab Room Manager:

- **Audition Scores** — one Voice Part's full per-judge, per-factor score table, best to worst. PDF only.
- **Combined Audition Scores (Confidential)** — every Voice Part in one Ensemble (or "All" for every Ensemble at once) with full candidate identity shown. PDF/CSV; the "All Ensembles" export is large enough that it's generated in the background and emailed to you rather than downloaded immediately.
- **Combined Audition Scores (Public)** — the same report with student/school/teacher identity stripped out, showing only candidate numbers. This is the version that gets auto-shared with teachers when "Share Results" (§6) is turned on.
- **Ensemble Participation** — every accepted member of one Ensemble with contact info and the optional fields this Version collects. PDF/CSV.
- **Student Seniority** — for each currently accepted member of one Ensemble, whether they were also accepted in that same Ensemble's prior Versions (a cross-Version look-back), grouped by graduating class year. PDF/CSV.

## 16. Registration Manager reports

**Page: Reports** (`/events/versions/{version}/reports`)

Nine reports, available to you or a Registration/Co-Registration Manager on this Version, plus **Downloads** for Event Managers only. A Registration Manager (or you) sees every county with an optional filter; a Co-Registration Manager is automatically locked to only their assigned counties, with no filter shown.

1. **Obligated Teachers** — every teacher who's accepted this Version's Obligations. PDF.
2. **Participating Teachers** — teachers with at least one Registered candidate. PDF.
3. **Participating Schools** — one row per school/teacher pair with a candidate who registered, whatever the audition outcome, or with money already paid: fees due/paid/balance, a **packet received** checkbox, and a **Payment** button for manually recording a check/PO/cash payment. Checking "packet received" doesn't email anyone by itself — click **Send Confirmations** to batch-email every teacher whose packet is marked received but not yet confirmed. Unchecking a packet afterward does *not* un-send an already-sent confirmation.
4. **Payment Reconciliation** — every payment transaction (electronic or manually recorded) for the Version, with an allocation status of fully allocated, partially allocated, or **Needs Reconciliation**. Totals at the top show what's due, waived, and paid, and each school has its balance; Event Managers can also open payment details and fee waivers. A manually-recorded lump-sum payment lands here unallocated until you open it and assign specific dollar amounts to specific candidates — until allocated, it doesn't count toward any candidate's balance.
5. **Participating Candidates** — the full Registered roster with an Edit modal (name, voice part, home/cell phone, emergency contact) and a Remove action that withdraws a candidate (Withdrew or Teacher Withdrawn). Event Managers also see **Waive fees**. Changing the voice part here follows the same rules as Voice Part Audit (§12): only parts offered for the student's grade, a question about any recordings, and no change once scores exist.
6. **Participation by County** — one row per county: obligated-teacher count, participating-teacher count, candidate count, and the responsible manager's name. Counties with zero activity still show up. PDF/CSV.
7. **Candidate Counts** — one row per school/teacher with a count column per voice part plus a total, and a Version-wide summary header. PDF/CSV.
8. **Adjudication Backup** — real navigation and export buttons exist, but the actual content is placeholder ("no in-person audition scheduled") because room-to-candidate assignment isn't part of this system yet — don't mistake this for a bug. If you need paper score sheets as a backup, prepare your own.
9. **Registration Cards** — same situation: real filters and a working print link, but placeholder PDF content for the same reason as above.

Participating Teachers, Participating Candidates, Participation by County, and Candidate Counts include only candidates whose status is **Registered**, so they empty out as cut-offs resolve candidates. Export them before you apply cut-offs if you'll need them.

### Fee waivers

Only an Event Manager can waive a fee; Registration Managers can see waivers but not change them. Choose **Waive fees** on Participating Candidates, check the fees, and give a reason, which only Event and Registration Managers see. A waived fee is $0 for that student everywhere it's charged. Uncheck it to charge the fee again. To waive fees after cut-offs or close, use **Fee waivers** on Payment Reconciliation.

### Downloads

CSV files of your Event's data: Event/Version settings, Schools, Teachers, Students, and Candidates. **Ensemble Participants** adds one file per Ensemble, listing its accepted candidates with school, teacher, voice part, score, and contact details, including up to three emergency contacts. Those files are built when you close the audition; choose **Regenerate** after a decline or a corrected detail. All of these contain personal information, so share them carefully.

## 17. Registration, in brief

You won't personally do most registration-phase work — that's the teacher's and (once StudentFolder.info is involved) the candidate's job — but it's worth knowing what they're up against, since your Requirements tab settings (§6) directly control it. A Candidate only reaches **Registered** once every milestone your Version requires is complete: a voice part offered for the student's grade, the fields you checked under Requirements (emergency contact with any required cell and email, birthday, height, shirt size, home address), application signatures once the Application is published, and — if remote auditions are configured — every required upload approved by the teacher, with none uploaded for a different voice part. Anything partially done sits at **pending**. Teachers see a checklist explaining exactly what's still missing, so "why isn't this student Registered?" should always have a visible answer on their end.

## 18. Common questions and gotchas

- **A teacher rejected Obligations — what happens to their students?** Every Candidate they'd enrolled in an active pre-adjudication state is automatically withdrawn. If the teacher later accepts, those same candidates are automatically reinstated and re-checked against the registration checklist — you don't need to do anything manually on either side.

- **I can't remove an invitation.** Once a teacher has moved past "Invited" (they've accepted Obligations or gone further), the Invitations page blocks removal and tells you why instead of silently failing.

- **Draft vs. Published, for Obligations and the Application.** A document in Draft status is completely invisible to teachers/candidates — publishing is a distinct, explicit action, not something that happens automatically when you save.

- **Ensemble Cut-offs won't open.** You need a Cut-off Strategy chosen on the Version's Configure → General tab first — it's a required prerequisite, not optional configuration.

- **Closing a Version via Configure vs. via Close Audition — what's the difference?** Setting Status to Closed on the Configure screen's General tab changes the Version's status alone. Only the Tab Room's dedicated **Close Audition** page also stamps the separate flag that actually makes results visible to teachers. This split is intentional, so closing a Version for an unrelated administrative reason never silently exposes results early.

- **Reopening after Close doesn't undo acceptances.** Reopen only hides results from teachers again (for a correction window) and flips the Version back to Active — it never reverses any candidate's accept/not-accept decision. Make a correction with Ensemble Cut-offs or Add/Edit Scores, then close again. It also cancels the platform invoice if that hasn't been sent.

- **Close Audition was refused.** Some Registered candidates are still undecided. The page lists them by voice part; finalize or apply the missing cut-offs, then close again (§14).

- **My Version won't activate.** Finish the before-registration items on the Setup checklist (§4).

- **A teacher says a student won't become Registered.** Check whether the Final Teacher Changes date (or, without one, the Postmark Deadline) has passed. After it, teachers' changes can't register a candidate (§6). Registration History shows what's missing and what happened when (§12).

- **Payment vendor credentials are per-Event, not per-Version.** Every Version of the same Event shares one Square or PayPal business account. If a manual payment you recorded isn't showing up against a candidate's balance, check whether it's sitting unallocated on the Payment Reconciliation report — a lump-sum payment doesn't count toward anyone's balance until you allocate it to specific candidates.

- **Changing a candidate's voice part in Tab Room deletes their scores.** Add/Edit Scores warns you before this happens, but it's worth knowing in advance: since scores are tied to a voice part's specific rubric, a voice-part change has no scores left to keep. The candidate then appears in the new voice part's Room, and those judges need to score them.

- **A judge can't see the Adjudicate button.** They must be assigned to a Room, the Version must be Active, the Adjudication window must be open, and they can't be a currently enrolled student (§13).

- **A Room never turns green.** Everyone in its judge list must score every candidate. Look for a judge who hasn't finished, or a monitor who was listed but isn't scoring (§10).

- **I need to replace a judge partway through.** Removing the original judge deletes the scores they've saved, after a warning (§10). Add the new judge and decide what should happen to the original scores before you remove anyone.

- **A Registration Manager can only be assigned once per Version.** Assigning a second is blocked until the first is removed — this keeps the Estimate Form's mail-to fallback address unambiguous.
