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

- Completion over the window: daily activity, and when each Room and each judge finished.
- How judges worked: sittings per judge, candidates per sitting, pace per candidate (scales vs solos), and time of day / day of week.
- Possible fatigue: pace and score drift from the start to the end of a sitting.
- Listening behavior (Versions tracked from the start only, so Version 88 onward): time from opening a candidate to saving, how much of each recording was heard compared with its length, replays, and whether judges worked through the roster in order.
- Planning takeaways, e.g. Room sizes that fit in one sitting, window length, and when reminders are most effective.

### 3. Selection

- Cut-off results per ensemble and voice part: accepted, not accepted, no-show and incomplete.
- Score distributions around each cut-off, and how out-of-tolerance candidates were resolved.
- Acceptances and declines after results were released.

## Prerequisites

- **Snapshot the analytics when the Version closes.** Store each section's computed metrics in a per-Version table at Close Audition (the same hook as the platform invoice; see `close-audition-invoice.md`). The report and any cross-Version or year-over-year comparison read those snapshots, not live data, so later score edits or deletions can't change history.
- **Tracking data is partial for Version 93.** `adjudication_events` and `recordings.duration_seconds` only exist from the 2026-11-17 deploy onward. Version 93's listening-behavior measures must be labeled partial or left out. Version 88 (window opens 2026-12-03) is the first Version tracked from the start.

## Open questions

- **How to render the PDF.** The existing PDFs use DomPDF, which can't run the Flux charts' JavaScript. The options are server-rendered SVG charts in a DomPDF template, or headless Chromium printing a dedicated report page. Avoid the browser Print dialog: it has produced trailing blank pages on Claude Artifacts.
- **Delivery.** Email the PDF to Event Managers when the Version closes (like the platform invoice), offer it as a download on the Scorecard, or both.
- **PowerPoint.** A .pptx version is possible. Start with PDF unless Event Managers ask for slides.
- **Comparison groups.** Which earlier Versions count as comparable: same Event in earlier years, other Events of the same audition type, or both.
