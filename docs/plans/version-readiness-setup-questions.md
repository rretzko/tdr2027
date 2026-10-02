# Phase C — Setup Questions (draft for review)

**Status:** Draft question set, 2026-10-02. **Not built.** Companion to
`docs/plans/version-readiness.md` (Phase C in §8). Decisions A–E accepted 2026-10-02 (§8);
Q2 revised the same day to separate *application format* from *mailing* (Q2b, §2a); G
accepted. §2a (`mail_required`) built 2026-10-02 ahead of the questionnaire; **the
questionnaire itself built 2026-10-02** — see §9.

---

## 1. What this is

A short questionnaire an Event Manager answers **once, when an Event's first Version is
created**, before they reach the setup checklist. Each answer:

1. **writes a real setting** — the same field the Configure page (or another setup page)
   edits; nothing new is stored except the answers' effects;
2. **marks the matching checklist item reviewed** (answering *is* the decision), using the
   existing `VersionReadiness::acknowledge()`;
3. **lets the checklist hide what no longer applies** — automatically, via the catalog's
   existing applicability rules. The questionnaire never hides items itself.

Goal: the manager lands on a checklist that is shorter, partly done, and entirely relevant.

**Principles**
- Plain language — no TDR vocabulary in the questions ("Version", "class-of", etc.).
- Every question is skippable; skipping leaves today's defaults and the item stays
  "Needs review" on the checklist. "Skip all" goes straight to the checklist.
- About a minute: one screen per part, not one screen per question.
- Never deletes data. Re-running it (see §5) changes settings only.

---

## 2. Part 1 — The shape of your event

These decide which checklist items apply at all.

### Q1. How do students audition?
| Answer | Writes | Notes |
|---|---|---|
| **They send recordings** | `versions.audition_type = remote` | Follow-up Q1a |
| **In person, at a scheduled time** | `audition_type = in_person`, `upload_type = none` | Follow-up Q1b |

- **Reviews:** `version.audition_format` (together with Q3).
- **Why both fields:** students only see upload slots when the audition is remote, and the
  "What recordings…" item only applies when `upload_type ≠ none`. Setting them together
  prevents a remote event with nothing to upload.
- **Decided (A):** in-person events don't collect recordings — in-person sets
  `upload_type = none`; a rare exception can change it on Configure.

**Q1a (recordings) — Audio or video?**
| Answer | Writes |
|---|---|
| Audio | `upload_type = audio` |
| Video | `upload_type = video` |

Turns **on** `version.upload_files`.

**Q1a-ii (optional) — What will each student record?** A short list of names, e.g.
"Scales", "Solo", "Sight-reading". Creates `version_upload_files` rows in order →
completes `version.upload_files`. Leave blank to do it later.

**Q1b (in person) — How long is each audition?** Minutes, default 20 →
`versions.audition_timeslot` (Configure requires ≥ 5 for in-person).

### Q2. How do students submit their application?
| Answer | Writes |
|---|---|
| **Printed and signed on paper (PDF)** | `application_type = pdf` |
| **Signed online in StudentFolder** | `application_type = eapplication` |

Reviews `version.audition_format` (with Q1). This decides **which application workflow**
students and teachers use (PDF adds the teacher/principal endorsement section). It does
**not** decide whether anything is mailed — see Q2b. The application *text* is still written
on the checklist's Application item — not here.

### Q2b. Must teachers send physical materials through the mail to complete registration?
*(e.g. signed paper applications, checks, membership cards, forms)*

| Answer | Writes | Checklist effect |
|---|---|---|
| **Yes** | `versions.mail_required = true` *(new field — §2a)* | Turns **on** the postmark-deadline date and "Where do teachers mail their paperwork?" |
| **No** | `mail_required = false` | Both items disappear |

- This is now the **only** thing that decides whether those two items apply — independent of
  Q2 and Q3. An online-application event that collects checks by mail answers **yes**.
- **Decided (G):** when Q2 = PDF, pre-select "Yes", changeable — some events collect paper
  applications in person at the audition.

### 2a. Schema change Q2b requires (applies to the existing checklist too)

Today, the catalog switches these two items on from the application format:

| Item | Current rule | New rule |
|---|---|---|
| `version.dates.postmark_deadline` | `application_type = pdf` | `mail_required` |
| `version.roles.mail_to` | `application_type = pdf` **or** membership card required | `mail_required` |

So Q2b needs a real setting, editable outside the questionnaire:

- **Migration:** `versions.mail_required` boolean, default `false`.
- **Backfill** (same migration) to keep every existing Version's checklist unchanged:
  `mail_required = true` where `application_type = 'pdf'` **or** the Version's membership
  requirement has `membership_card = true`.
- **Configure → Requirements:** a "Teachers mail physical materials" checkbox next to the
  membership-card setting; saving it reviews both items (Requirements section).
- **Catalog:** both items' `applicable` rule becomes `mail_required`, and `mail_required` is
  added to the postmark item's `covers` (the coverage test fails until it's covered).
- **Cloning:** `VersionCloningService` copies `mail_required` with the other Version fields.
- **Unchanged:** the postmark date is still *printed* on PDF applications when set
  (`CandidateApplicationData`), and the Estimate Form's Mail-To page still resolves the same
  way — this only changes when the checklist *asks* for them.

### Q3. Must teachers hold a current membership card?
| Answer | Writes | Checklist effect |
|---|---|---|
| Yes — valid through *[date]* | `version_membership_requirements.membership_card = true`, `valid_thru` | — |
| No | `membership_card = false` | — |

Reviews `version.membership`. Whether the card is *mailed* is covered by Q2b, so this
question no longer switches any items on.

### Q4. Payments *(revised 2026-10-02)*

How payment works: at the end of registration each **teacher settles the balance** for all
of their students — by check, or online. Separately, a teacher may **let their students pay
online** through StudentFolder; student payments are optional and credited toward the
teacher's balance. The two are independent, and every combination is valid.

**Q4a. Can teachers pay their balance online?**
| Answer | Writes (`version_epayment_configs`) |
|---|---|
| No — teachers pay by check | `epayment_teacher = false`, `online_payment_required = false` |
| Yes — online or by check | `epayment_teacher = true`, `online_payment_required = false` |
| Yes — online only | `epayment_teacher = true`, `online_payment_required = true` |

**Q4b. Can teachers let their students pay online through StudentFolder?** Yes / No →
`epayment_student`. "Yes" gives each teacher the opt-in toggle on their registration
dashboard; it never makes students pay.

- Either "yes" turns **on** "Connect your Square or PayPal account" (Square setup must be done
  by the account owner).
- `version.epayment.decision` is confirmed once **both** are answered; answering one writes
  only its own flags.
- **"Online only"** (new column `online_payment_required`, migration
  `2026_10_02_000004`) is informational: teachers see "This event accepts online payment only
  — please pay your balance online, not by check." on their registration dashboard (Group
  Payment area), the Estimate Form page, and the Estimate Form PDF. Managers can still record
  a check or purchase order as an exception. It never applies to student payments, and is
  ignored unless teachers can pay online (`Version::onlinePaymentRequired()`). Editable on
  Configure → Payments; copied on clone.

### Q5. What ensembles will students be placed into?
*Asked only when the Event has no ensembles yet.*

A list of names (e.g. "Mixed Chorus", "Treble Choir") → creates `ensembles` rows for the
Event. Completes `event.ensembles`; turns **on** grades and voice parts per ensemble, and
ensemble order when there's more than one.

- **Decided (B):** names only; grades and voice parts stay on the checklist, which links to
  the Ensembles tab.

### Q6. How many judges score each student?
Number, default 1 → `versions.judge_count`. Reviews `version.judge_count`. Also sets how many
judges each room needs on the "Who judges in each room?" item.

---

## 3. Part 2 — Quick yes / no

Optional features. A **"no"** confirms the item as *"we don't use this"* (marks it Done); a
**"yes"** leaves it open on the checklist so the manager sets it up there.

| # | Question | "No" writes / confirms | "Yes" leaves open |
|---|---|---|---|
| Q7 | Is the event limited to certain counties? | no counties (= any school) · confirms `version.counties` | county picker on Configure → Requirements |
| Q8 | Will county co-managers share registration work? | confirms `version.roles.co_registration` | Co-Registration Managers page |
| Q9 | Must teachers agree to obligations before taking part? | confirms `version.obligations` | Obligations tab |
| Q10 | Will you provide practice tracks or pitch files? | confirms `version.pitch_files` | Pitch Files page |
| Q11 | Is there a cap on registrants (overall, per school, or upper voices)? | all caps empty · confirms `version.caps` | caps on Configure → General |

- **Decided (C):** keep Part 2 — the fastest way to shrink the list, and each "no" is a real
  decision.

---

## 4. What it does to the checklist (worked example)

A first Version with: recordings (audio, three named), online application, **nothing
mailed**, no membership card, teachers pay by check and students don't pay online, two ensembles named, 1 judge, and "no" to
all of Part 2.

| Before (fresh first Version) | After the questions |
|---|---|
| 32 applicable items · 1 Done | 34 applicable · 12 Done |

*(Measured against the real catalog, 2026-10-02.)* The list doesn't get shorter here — it
gets **truer**: naming ensembles switches on three real next steps and listing recordings
switches on (and completes) one, while two items that don't apply drop out.

- **Hidden:** postmark date, mail-to address (nothing mailed). The Square/PayPal item was
  never on, since online payments default to off.
- **Turned on and already done:** recordings list.
- **Turned on, still to do:** grades and voice parts per ensemble, ensemble order.
- **Confirmed by answers:** audition format, membership, online payments, ensembles, judge
  count, counties, co-managers, obligations, pitch files, caps.

---

## 5. When it appears, and who sees it

- **Shown:** automatically after **creating an Event's first Version** (not a cloned one —
  cloning already carries last year's answers forward). Event Managers and the Founder only,
  since every answer changes an Event-Manager-only setting.
- **Re-run:** a "Setup questions" button on the checklist, pre-filled with current values.
  Changing an answer rewrites the setting; it never deletes data (e.g. switching to
  in-person keeps any recordings list already entered).
- **Decided (D):** re-run is available on cloned Versions too — useful when an event changes
  format between years.
- **Decided (E):** its own page, `events/versions/{version}/setup-questions`, which first-
  Version creation redirects to.

---

## 6. Deliberately not asked

| Topic | Why not |
|---|---|
| Dates, fees | Need real numbers and dates; better entered on their tabs with context. |
| Application wording, obligations wording | Long-form text. |
| Eligible grades | Optional filter on top of ensemble grades; confusing this early. |
| Rooms, judges' names, Tab Room Manager | Usually not known at setup time. |
| Rubric | Event default already exists; customizing is a later, expert step. |
| Score order, cut-off strategy, share results | Results-time decisions; the checklist covers them in their own stage. |
| Student data requirements (birthday, height, …) | Six toggles with sensible defaults; one screen on Configure handles them faster. |

---

## 7. Build sketch (for after review)

- First, the §2a schema change (migration + backfill, Configure checkbox, catalog rules,
  cloning) — shippable on its own, since it fixes the checklist for online-application
  events that still collect mail.
- `SetupQuestions` Livewire page, two parts, follow-ups revealed inline.
- `App\Services\Readiness\SetupAnswers` — one method per question that writes the setting
  and calls `VersionReadiness::acknowledge()` for the items it decides; unit-tested per
  answer.
- `Events\Show::createVersion()` redirects here when the Event had no prior Version.
- Tests: each answer writes the right field and reviews the right items; skipping writes
  nothing; re-run never deletes; only Event Managers / Founder can open it.

---

## 8. Your decisions, in one place

| # | Question | Decision |
|---|---|---|
| A | In-person events ever collect recordings? | **Accepted:** no — in-person sets `upload_type = none` |
| B | Grades/voice parts per ensemble in Q5? | **Accepted:** names only |
| C | Keep Part 2 (yes/no confirmations)? | **Accepted:** keep |
| D | Re-run available on cloned Versions? | **Accepted:** yes |
| E | Own page or modal? | **Accepted:** own page |
| F | Any question missing / not answerable on day one? | **Answered:** add Q2b (mailing), separate from application format |
| G | Pre-select Q2b "Yes" when Q2 = PDF? | **Accepted:** pre-select, changeable |

---

## 9. Build notes (2026-10-02)

- **Page:** `App\Livewire\Events\VersionSetupQuestions` at
  `events/versions/{version}/setup-questions` (`events.versions.setup-questions`), Event
  Manager / Founder only (`canManageEvent`). Two cards (Part 1, Part 2), follow-ups revealed
  inline, a "Clear answer" link per answered question, "Skip for now" top and bottom, and an
  error summary above Save.
- **Logic:** `App\Services\Readiness\SetupQuestionnaire` — `current()` (pre-fill),
  `apply()` (one transaction), `existingOptionalSetup()`; answers travel as the
  `SetupAnswers` value object; Q4a is the `App\Enums\TeacherPayments` enum, Q4b a bool.
- **Pre-fill rule:** a question shows a value only when its readiness item is already
  confirmed (earlier run, Configure save, or "Looks right") — so a first run starts blank and
  a re-run shows what was decided. Part 2 shows "Already set up — …" with a Review link when a
  feature has data, so "no" can never imply deleting it.
- **Entry points:** `CreateEvent::create()` and the no-prior-Version branch of
  `Events\Show::createVersion()` redirect here; cloned Versions don't. The Founder's
  create-event modal on `Events\Index` does not redirect (the Founder isn't the one setting
  the event up). The checklist shows a "Setup questions" button to Event Managers.
- **Lists:** recordings and ensembles are one per line, at most 10, blank lines ignored;
  recordings are only added when the Version has none, ensembles only when the Event has none.
- **Tests:** `tests/Feature/Services/SetupQuestionnaireTest.php` (13) and
  `tests/Feature/Livewire/Events/VersionSetupQuestionsTest.php` (10).
