# Version Analytics Report (end-of-version PDF for Event Managers)

Status: **planned, not started** (2026-11-17). Build it after Version 93's adjudication window closes on 2026-11-22, so the patterns it reports have been confirmed against a full window first.

## Goal

When a Version closes, give its Event Managers one PDF that explains how the event went, so they can plan the next cycle. The report covers each phase of a candidate's lifecycle and compares the Version with earlier events and earlier years once that history exists.

## Structure (decision, 2026-11-17)

The report has **three sections, one for each phase of a candidate's lifecycle**:

| # | Section | Phase covers | Primary sources |
|---|---|---|---|
| 1 | **Registration** | Invitation through the Postmark Deadline | `VersionScorecardService` (current numbers), `version_scorecard_snapshots` (weekly trends) |
| 2 | **Adjudication** | The adjudication window | `AdjudicationProgressService` + `scores` timestamps, `adjudication_events`, `recordings.duration_seconds` |
| 3 | **Selection** | Ensemble cut-offs through acceptance | `EnsembleCutoffService`, `version_ensemble_cutoffs`, `audition_results`, candidate statuses |

The Scorecard page (`/events/versions/{version}/scorecard`) follows the same structure. It already has **Registration** and **Adjudication** tabs. A **Selection** tab is the natural third tab, and the PDF can reuse what each tab computes.

### 1. Registration

- Invited, obligated and registered counts for teachers, schools and students, with the weekly trend lines leading up to the Postmark Deadline.
- Registration fees: due, paid, outstanding and waived.
- Conversion at each step (invited → obligated → registered) and when in the cycle registrations arrived.

### 2. Adjudication

The Scorecard's Adjudication tab already computes live progress (`AdjudicationProgressService`): per-Room and per-judge completion, sittings, pace, an on-time projection, Room stall flags and how much of each recording judges heard before scoring (`ListeningEstimate`). The report should reuse those calculations and add the following.

- Completion over the window: daily activity, and when each Room and each judge finished. Include the schedule history: how often the Version was behind, and whether judges who hadn't started (the main cause in Version 93) caught up.
- How judges worked: sittings per judge, candidates per sitting, pace per candidate (scales vs solos), and time of day / day of week. In Version 93, saves peaked at 9 am–1 pm and 7–9 pm Eastern, which bears on when reminders go out.
- **Judge agreement.** Per Room, for each pair of judges: correlation of totals, average gap and largest gap, and how many candidates fell outside the Room's tolerance. In Version 93 pairs correlated at 0.67–0.86 and no candidate exceeded tolerance (largest gap 7, tolerance 10). Flag tolerances that can never trigger: 10 is very loose for a three-factor Solos rubric with totals from 3 to 27.
- **Calibration.** Each judge's average total compared with their co-judges' totals on the same candidates, to show harsh or lenient scoring. In Version 93 every judge was within 0.8 points of their co-judges.
- **Scale use.** How much of the 1–9 range each judge used, and the share of candidates who got the same score on every factor. In Version 93 one judge gave 4 or 5 on 81% of factor scores, and 16–29% of candidates got the same score on every factor from several judges.
- **Possible fatigue / score drift**, from the start to the end of a sitting and across a judge's whole Room. Measure it relative to co-judges on the same candidates, not as raw drift: the roster is ordered by voice part, so a judge's raw totals change as the voice parts change. In Version 93 both judges in one Room got harsher in the middle third together, which reflects the candidate mix rather than fatigue.
- **Review passes.** Candidates a judge reopened and re-scored after their first save, how far the scores moved, and how many recordings they listened to again. In Version 93 one judge swept back over the whole roster and revised 25 candidates, replaying only about 10 recordings.
- Listening behavior (Versions tracked from the start only, so Version 88 onward): time from opening a candidate to saving, how much of each recording was heard compared with its length (summarize the Scorecard's Heard measure per Room and judge), replays, and whether judges worked through the roster in order (`roster_position`).
- Planning takeaways, e.g. Room sizes that fit in one sitting, window length, tolerance settings and when reminders are most effective.

Performance: `ListeningEstimate` reads every tracked event for the Version. A synthetic all-state Version (1,000 candidates × 3 recordings, 30 judges, 27k–48k events) took 0.7–1.2s per Scorecard refresh. Computing these measures once at Close Audition for the snapshot is fine, but don't join `adjudication_events` to a derived table of `scores`: without an index on (room_judge_id, candidate_id, created_at), MariaDB ran that join as a nested loop, about 10s at 48k events.

### 3. Selection

- Cut-off results per ensemble and voice part: accepted, not accepted, no-show and incomplete.
- Score distributions around each cut-off, and how out-of-tolerance candidates were resolved (see judge agreement under Adjudication).
- Acceptances and declines after results were released.

## Prerequisites

- **Snapshot the analytics when the Version closes.** Store each section's computed metrics in a per-Version table at Close Audition (the same hook as the platform invoice; see `close-audition-invoice.md`). The report and any cross-Version or year-over-year comparison read those snapshots, not live data, so later score edits or deletions can't change history.
- **Tracking data is partial for Version 93.** `adjudication_events` and `recordings.duration_seconds` only exist from the 2026-11-17 deploy onward. Version 93's listening-behavior measures must be labeled partial or left out. Version 88 (window opens 2026-12-03) is the first Version tracked from the start.

## Open questions

- **How to render the PDF.** The existing PDFs use DomPDF, which can't run the Flux charts' JavaScript. The options are server-rendered SVG charts in a DomPDF template, or headless Chromium printing a dedicated report page. Avoid the browser Print dialog: it has produced trailing blank pages on Claude Artifacts.
- **Delivery.** Email the PDF to Event Managers when the Version closes (like the platform invoice), offer it as a download on the Scorecard, or both.
- **PowerPoint.** A .pptx version is possible. Start with PDF unless Event Managers ask for slides.
- **Comparison groups.** Which earlier Versions count as comparable: same Event in earlier years, other Events of the same audition type, or both.
