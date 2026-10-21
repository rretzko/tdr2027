# Plan: Per-recipient tracking for the director weekly update email

**Status: on hold (2026-10-21).** We're waiting to see how the Wednesday
sends go with the current log-only tracking before building this.

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
