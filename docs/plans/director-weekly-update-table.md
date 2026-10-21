# Plan: Per-recipient tracking for the director weekly update email

**Status: mostly superseded (2026-10-21). Don't build unless the trigger at the bottom fires.**

Production mail is moving from SES to Postmark (branch `postmark-mailer`).
The director weekly update and the Monday scorecard go on the `broadcast`
stream. Postmark's Activity view keeps per-message history for 45 days,
searchable by recipient, with delivered, bounced and opened statuses. That
answers "did this director get last Wednesday's update?" without this table.

Also since this plan was written:

- `LOG_CHANNEL=stderr` is set in production, which should fix the CloudWatch
  gap described below once deployed. That still needs checking.
- Unsubscribes are now app-managed (branch `weekly-email-unsubscribe`,
  `email_unsubscribes` table), and both digests stop when adjudication begins.
  Unsubscribed directors are skipped silently. If this table is ever built,
  record them as `skipped_unsubscribed`.

This table would still add history older than Postmark's retention, and
in-app visibility for Event Managers (section 3). The original plan follows,
unchanged.

## Goal

Record every director weekly update send attempt (sent, failed or skipped)
in its own table. Then "did this director get last Wednesday's update?" is
answered with a query instead of a CloudWatch search.

## Current state

`SendDirectorWeeklyUpdateEmails` (`versions:send-director-weekly-update`) is
scheduled in `routes/console.php` for Wednesdays at 8:00 AM `America/New_York`.
It tracks sends only through logs:

- One `Log::info` summary per run, with counts: `invitations`, `emails_sent`, `failures`.
- One `Log::warning` per failed send, with `version_invitation_id` and `error`.

**Finding (2026-10-21): these log lines don't reach CloudWatch in production.**
The Monday scorecard run (~11:30 UTC on 2026-10-19) shows up in
`/aws/lambda/vapor-tdr2027-production-cli` as a 13.8 s invocation, but only
Lambda's START/END/REPORT lines were recorded. `.env.production` doesn't set
`LOG_CHANNEL`, so it falls back to `stack` → `single` (a file on Lambda that
gets thrown away). Setting `LOG_CHANNEL=stderr` in production should fix it.
Until then, the only record of a run is SES's account-wide send counts, which
are shared with every other Vapor app on this AWS account.

Gaps:

- No per-recipient record. The summary has counts only.
- No history after CloudWatch log retention expires.
- Directors with no email address are skipped silently. They aren't counted or logged.
- "Sent" means the mail transport accepted the message. Delivery, bounces
  and opens aren't visible in the app.

## 1. Table: `director_weekly_update_sends`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `version_invitation_id` | FK → `version_invitations`, cascade on delete | |
| `version_id` | FK → `versions`, cascade on delete | Denormalized for per-Version queries without a join |
| `email` | string, nullable | The address used at send time (users can change it later). Null when skipped. |
| `status` | string (backed enum) | `sent`, `failed`, `skipped_no_email` |
| `error` | text, nullable | Exception message on `failed` |
| `run_id` | uuid | Groups the rows from one command run |
| `sent_at` | timestamp | When the attempt happened (UTC) |
| `timestamps` | | |

Indexes: `(version_id, sent_at)`, `(version_invitation_id, sent_at)`, `run_id`.

Naming: this is send history keyed by invitation, not a version-owned child
config table, so the `version_` prefix rule doesn't apply. Revisit if it
ends up displayed as a Version sub-page.

## 2. Code

- `App\Enums\DirectorWeeklyUpdateSendStatus` (backed string enum with `label()`).
- `App\Models\DirectorWeeklyUpdateSend` + factory. Casts: status enum,
  `sent_at` datetime. Relations: `versionInvitation()` and `version()`.
- `SendDirectorWeeklyUpdateEmails::handle()`:
  - Generate one `run_id` per run.
  - Write one row per invitation in the loop: `skipped_no_email` (replacing
    the bare `continue`), `sent` after a successful `Mail::send`, or `failed`
    in the `catch` with the error message.
  - Keep the existing log lines and add a `skipped` count to the summary.
  - A failure to write a tracking row must not stop the send loop (wrap it
    in try/catch or write the row after the send).

## 3. Optional UI (decide when building)

- Event Manager Invitations page: a "Last weekly update" column (date +
  status badge), plus a modal with that director's send history.
- Responsive card/table layout, per the project's table standard.

## 4. Optional later work

- **Delivery status:** a mail-provider webhook (SES SNS / Postmark) that
  updates the row to `delivered` / `bounced`. Needs the provider's message
  ID stored at send time (`SentMessage::getMessageId()`), which means adding
  a `provider_message_id` column.
- **Retention:** prune rows older than N months with a scheduled
  `model:prune` (`Prunable` on the model).

## 5. Tests

Extend `tests/Feature/Console/SendDirectorWeeklyUpdateEmailsTest.php`:

- One `sent` row per emailed director, with the correct `email` and `run_id`.
- No rows for Rejected invitations and no rows for inactive Versions.
- `skipped_no_email` row for a director without an email.
- `failed` row with the error when the mailer throws. Later directors are
  still sent.
- All rows from one run share a `run_id`.

## Trigger to revisit

Build this if a director reports not getting the update and the logs can't
settle it, or if Event Managers ask to see who got the weekly email.
