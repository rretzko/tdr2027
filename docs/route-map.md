# Route Map

Reference index of every named route in `routes/web.php`, grouped by portal and role gate. Useful for onboarding and as a standing reference as the route tree grows — not a security audit; check the route file directly for anything access-control-sensitive.

A styled, browsable version of this map (sticky index, collapsible report tables) is also published as a Claude artifact; this file is the source-of-truth copy that lives with the code.

## Public

No auth. Reachable by anyone, including bots and payment gateways.

| Method | Path | Destination | Notes |
|---|---|---|---|
| GET | `/` | Welcome (TDR or SFDI) | View chosen by request host vs. `sfdi_domain` config |
| GET | `/sfdi` | SFDI welcome (path fallback) | For local/staging without an SFDI hosts-file entry |
| POST | `/webhooks/payments/square/{event}` | Square webhook | `throttle:payment-webhooks` |
| POST | `/webhooks/payments/paypal/{event}` | PayPal webhook | `throttle:payment-webhooks` |
| GET | `/payments/paypal/return` | PayPal browser return | No signature — the payer's browser lands here, not PayPal's servers |

## Guest entry points

Gate: `guest` (plus the social callback, reachable either way).

| Method | Path | Destination | Notes |
|---|---|---|---|
| GET | `/tdr/register` | Teacher registration | |
| GET | `/sfdi/register` | Student registration | |
| GET | `/sfdi/login` | Student login | |
| GET | `/auth/{provider}/redirect` | Social login redirect | |
| GET | `/tdr/social/phone` | Social phone check | |
| GET | `/auth/{provider}/callback` | Social login callback | `throttle:social-callback`; outside the guest group — also handles email-match for an already-authenticated user |

## Signed email links

Gate: `signed` — no session required; the URL's signature is the credential.

| Method | Path | Destination | Notes |
|---|---|---|---|
| GET | `/school-email/verify/{schoolTeacher}` | School email verification | |
| GET | `/student-claim/{student}/{teacher}/{school}/approve` | Student claim — approve | Emailed to the student's existing teacher(s) |
| GET | `/student-claim/{student}/{teacher}/{school}/deny` | Student claim — deny | |
| GET | `/version-invitation-requests/{versionInvitationRequest}/{user}/approve` | Version invitation — approve | Emailed to a specific Event Manager |
| GET | `/version-invitation-requests/{versionInvitationRequest}/{user}/deny` | Version invitation — deny | |

## Account setup

Gate: `auth` (onboarding wizard adds `verified`) — kept outside the `onboarding.complete` group below, or either page would redirect into itself.

| Method | Path | Destination | Notes |
|---|---|---|---|
| GET | `/tdr/profile/complete` | Social profile completion | auth only — email may not be verified yet |
| GET | `/tdr/onboarding` | Teacher onboarding wizard | + `verified` |

## Founder console

Gate: `auth`, `verified`, `founder` — a Founder account has no Teacher profile, so it stays outside `onboarding.complete` too.

| Method | Path | Destination | Notes |
|---|---|---|---|
| GET | `/founder/impersonate` | Impersonate | |
| GET | `/founder/trackable-pages` | Trackable pages | |
| GET | `/founder/merge-students` | Merge students | |
| GET | `/founder/teacher-verification` | Teacher verification | |
| GET | `/founder/issues` | Issues | |
| POST | `/founder/stop-impersonating` | Stop impersonating | Gate: `auth` only — the active user during impersonation is the teacher, not the Founder; the controller checks the session itself |

## Universal account

Gate: `auth`, `verified`, `onboarding.complete` — reachable by any account, teacher or student, regardless of portal.

| Method | Path | Destination | Notes |
|---|---|---|---|
| GET | `/dashboard` | Dashboard | + `student.has.active.school` |
| GET | `/feedback` | Feedback | |
| GET | `/schools` | Schools index | |
| GET | `/organizations` | Organizations | |
| GET | `/settings/profile` | Profile settings | |
| GET | `/settings/password` | Password settings | |

## Student portal — SFDI

Same base gate as Universal Account, above. School/Details/Contacts stay open with no active school — that's how a student gets one; Events additionally needs `student.has.active.school`.

| Method | Path | Destination | Notes |
|---|---|---|---|
| GET | `/sfdi/school` | School | Doubles as the first-run destination for a student with no school yet |
| GET | `/sfdi/student-details` | Student details | |
| GET | `/sfdi/emergency-contacts` | Emergency contacts | |
| GET | `/sfdi/events` | My events | + `student.has.active.school` |
| GET | `/sfdi/events/{candidate}` | Event / candidate detail | + `student.has.active.school` |

## Teacher portal — TDR

Additional gate: `has.active.school` — Students and Registrations both need an active school to attach records to.

| Method | Path | Destination | Exports |
|---|---|---|---|
| GET | `/students` | Students index | |
| GET | `/registrations` | Registrations index | |
| GET | `/registrations/results` | Results index | |
| GET | `/registrations/{version}` | Version dashboard | |
| GET | `/registrations/{version}/request-invitation` | Request invitation | |
| GET | `/registrations/{version}/obligations` | Obligations | |
| GET | `/registrations/{version}/results` | Results | school report (PDF), shared scores (PDF) |
| GET | `/registrations/{version}/estimate-form` | Estimate form | per-school (PDF) |
| GET | `/registrations/{version}/pitch-files` | Pitch files | |
| GET | `/registrations/{version}/payment-register` | Payment register | CSV, PDF |
| GET | `/registrations/{version}/{candidate}` | Candidate detail | application (PDF), score report (PDF) |

## Events & version management

Additional gate: `can.access.events` — an active school OR a version-scoped role (Event Manager, Registration Manager, …) on at least one Version.

| Method | Path | Destination | Exports |
|---|---|---|---|
| GET | `/events` | Events index | |
| GET | `/events/new` | Create event | |
| GET | `/events/{event}` | Event overview | |
| GET | `/events/versions/{version}/edit` | Version edit | |
| GET | `/events/versions/{version}/invitations` | Invitations | |
| GET | `/events/versions/{version}/co-registration-managers` | Co-Registration Managers | |
| GET | `/events/versions/{version}/pitch-files` | Pitch files | |
| GET | `/events/versions/{version}/rooms` | Rooms | roster (PDF) |
| GET | `/events/versions/{version}/scoring-rubric` | Scoring rubric | |
| GET | `/events/versions/{version}/adjudicate` | Adjudicate | |
| GET | `/events/versions/{version}/web-registration` | Web registration | |

### Tab Room — `/events/versions/{version}/tab-room/…`

| Method | Path suffix | Destination | Exports |
|---|---|---|---|
| GET | `tab-room` | Tab room index | |
| GET | `tab-room/scores` | Add / edit scores | |
| GET | `tab-room/tracking` | Adjudication tracking | |
| GET | `tab-room/cutoffs` | Ensemble cutoffs | |
| GET | `tab-room/close` | Close audition | |
| GET | `tab-room/reports` | Tab room reports index | |
| GET | `tab-room/reports/audition-scores` | Audition scores | PDF |
| GET | `tab-room/reports/combined-scores/confidential` | Combined scores — confidential | PDF, CSV |
| GET | `tab-room/reports/combined-scores/public` | Combined scores — public | PDF, CSV |
| GET | `tab-room/reports/ensemble-participation` | Ensemble participation | PDF, CSV |
| GET | `tab-room/reports/student-seniority` | Student seniority | PDF, CSV |

### Registration Manager reports — `/events/versions/{version}/reports/…`

| Method | Path suffix | Destination | Exports |
|---|---|---|---|
| GET | `reports` | Reports index | |
| GET | `reports/obligated-teachers` | Obligated teachers | PDF |
| GET | `reports/participating-teachers` | Participating teachers | PDF |
| GET | `reports/participating-schools` | Participating schools | PDF |
| GET | `reports/payment-reconciliation` | Payment reconciliation | PDF |
| GET | `reports/participating-candidates` | Participating candidates | PDF |
| GET | `reports/participation-by-county` | Participation by county | PDF, CSV |
| GET | `reports/candidate-counts` | Candidate counts | PDF, CSV |
| GET | `reports/adjudication-backup` | Adjudication backup | paper, CSV, checklist |
| GET | `reports/registration-cards` | Registration cards | PDF |
