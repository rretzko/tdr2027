# TheDirectorsRoom.com — TDR2027
# Event & Version Domain — Orientation & Build Specification

**Audience:** Claude Code (PhpStorm) and human reviewers.
**Status:** In-scope build (§0.2 "Build now") implemented and tested, including Version Invitations (§5.4), Version Pitch Files (§5.5), and Version Obligations (§5.6, not yet committed). Verified against the codebase 2026-07-09: 522/522 Feature/Unit tests passing app-wide (500 baseline + 22 new for §5.6, added across `VersionEditTest`, `Registrations/VersionObligationsTest`, and `VersionObligationObserversTest`). Reference-only items (§7, adjudication/tab room/cut-offs) remain intentionally unbuilt. See §9 — all tracked items are resolved except intentionally-deferred sub-features. **Known open issue in §5.6**: bulleted/numbered lists don't render with visible markers in the live `flux:editor` admin UI or its Preview modal, even for freshly-typed (not pasted) content — confirmed the stored HTML and server-side rendering are both correct via direct DB inspection and a Blade-render test, so the defect is isolated to the client-side editor/TipTap layer, not this codebase's PHP/Blade/CSS. Unresolved; see the note at the end of §9. **Candidate Registration workflow designed 2026-07-16** (§5.8 Version Invitation Requests, new `candidate_upload_files` table in §5.2, and the access/navigation rules in §6.2) — scoped from a source design doc plus a clarifying-question pass; **not yet built**. **Audition Environment built 2026-07-29** (§5.9: `version_rooms`, `room_score_categories`, `room_voice_parts`, `room_judges`, `score_categories`, `score_factors`, and a schema-only `scores` table, plus the `VersionRooms` and `VersionScoringRubric` Event Manager/Registration Manager CRUD screens, and `VersionRoleAssignmentService::canManageAuditionEnvironment()`) — scoped from a source design doc plus a clarifying-question pass. 681/681 Feature/Unit tests passing app-wide, PHPStan clean. **Co-Registration Manager delegation built 2026-08-04** (§5.3): a Registration Manager can now assign/revoke Co-Registration Manager via its own screen, `VersionCoRegistrationManagers`, paired with mutually-exclusive county-of-responsibility assignment (`co_registration_manager_counties`) — no longer assignable from the generic Configure → Roles tab. 718/718 Feature/Unit tests passing app-wide, PHPStan clean.

---

## 0. Orientation

### 0.1 Purpose of this document

This document orients an AI coding agent and human reviewers to the **Event** and **Version** domain of the TDR2027 rewrite. It defines the domain vocabulary, the data model, the lifecycle, and the business rules required to configure an event and register candidates. It is written to be read top-to-bottom before any code is generated.

### 0.2 Scope of THIS phase

**Build now (in scope):**

- The Event, Event Grades, Ensemble, Ensemble Grades, and Ensemble Voice Parts data model.
- The Version data model and its configuration child tables (dates, fees, timeslots, roles, adjudication config).
- The Configuration lifecycle phase (Event Manager sets up a Version).
- The Registration lifecycle phase, including the candidate eligibility → registered gate and the poka-yoke status surface for teachers.

**Describe for context, do NOT build yet (out of scope for this phase):**

- Pre-adjudication reconciliation, Adjudication (judge scoring UI), and the Tab Room.
- Score cut-offs and ensemble-assignment algorithms. These belong to the **Adjudication Wizard, Release 2**. They appear in §7 so the model is forward-compatible, but no code should be scaffolded for them now.

> **Decision needed:** Confirm the in/out-of-scope split above matches your intended first PR. The agent will treat §7.1–§7.8 as reference only.

### 0.3 Stack & conventions

- Modular Laravel monolith (TDR2027), Livewire + Flux UI, Pest for tests, deployed on Laravel Vapor.
- Uploads are all saved to S3.
- Authorization uses **version-scoped Spatie roles** — roles are granted relative to a specific Version, not globally.
- Datetime pairs use the `start_at` / `end_at` naming convention throughout.
- Datetimes are stored as UTC and displayed at `America/New_York`.
- Status and category fields are backed by **PHP enums**, not free strings.
- Open/close windows, fees, and timeslots are modeled as **rows in child tables** (`version_dates`, `version_fees`, `version_timeslots`), not as wide columns on `versions`.

### 0.4 How to read the spec tables

The **Event** table in §3 is the worked reference for column specification (Column / Type / Null / Default / Notes). Every other table should be fleshed out to the same level of detail before migrations are generated. Where a type or rule is genuinely undecided, it is flagged with a **Decision needed** callout rather than guessed.

---

## 1. Domain Vocabulary

These terms have precise meanings in TDR2027 and must be used consistently in code, UI, and conversation. In particular, the person being auditioned is referred to by different terms at different lifecycle stages — do not treat them as interchangeable model names.

| Term          | Meaning                                                                                                                                                                                                                                     |
|---------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Organization  | The sponsoring body that owns one or more Events (e.g., a state music association). Top of the hierarchy.                                                                                                                                   |
| Event         | A periodic — generally annual — series of activities sponsored by an Organization that results in the creation of one or more Ensembles. Holds the relatively stable definition of the program.                                             |
| Version       | A single periodic iteration of an Event. Holds the rules, dates, fees, and configuration needed to run that one occurrence.                                                                                                                 |
| Ensemble      | A choir produced as the output of a Version (e.g., Mixed Chorus, Treble Choir).                                                                                                                                                             |
| Voice Part    | A reference value (S1, S2, A1, A2, T1, T2, B1, B2, etc.). Defined in §4.3.                                                                                                                                                                  |
| **Student**   | A durable person identity (belongs to a school, has a teacher). Persists across Events and Versions.                                                                                                                                        |
| **Candidate** | A point-in-time enrollment of a Student into a specific Version — the snapshot that moves through the registration and adjudication lifecycle. Has a twelve-state status enum; see *Candidate states* below and the transition map in §8.3. |
| Teacher       | The teacher who registers and approves their Candidates for a Version.                                                                                                                                                                      |
| Roles         | Version-scoped responsibilities: Event Manager, Registration Manager, Co-Registration Manager, Web Registration Manager, Tab Room Manager, Rehearsal Manager.                                                                               |

**Candidate states** (status enum, in lifecycle order):

- `eligible` — version qualified.
- `pending` — at least one, but not all, required registration milestones met.
- `registered` — all required registration milestones met.
- `withdrew` — candidate withdrew themselves; post-registered, pre-adjudication.
- `teacher_withdrawn` — teacher withdrew the candidate; post-registered, pre-adjudication.
- `adjudicated` — candidate completed the adjudication process.
- `no_show` — adjudication result: candidate did not show up to be adjudicated.
- `incomplete` — adjudication result: candidate started but did not complete the audition.
- `accepted` — adjudication result from cutoff assignment: assigned to an ensemble.
- `not_accepted` — adjudication result from cutoff assignment: not assigned to an ensemble.
- `declined` — candidate refused acceptance into a Version ensemble; post-adjudication.
- `removed` — candidate accepted to an ensemble but failed to meet ensemble rehearsal requirements (Rehearsal Manager decision).

---

## 2. Domain Model Overview

Relationship summary (read "1—\*" as one-to-many, "\*—\*" as many-to-many):

- `Organization` 1—\* `Event`
- `Event` 1—\* `Version`
- `Event` 1—\* `Ensemble` (Ensemble is owned by the Event, populated by a Version)
- `Event` 1—\* `EventGrade`
- `Ensemble` 1—\* `EnsembleGrade`
- `Ensemble` \*—\* `VoicePart` (via `ensemble_voice_parts`)
- `Version` 1—\* `version_dates`, `version_fees`, `version_timeslots`, `version_upload_files`, `version_adjudication`
- `Version` 1—\* `Candidate`
- `Candidate` 1—\* `candidate_status_history`
- `Version` 1—1 `VersionObligation` (via `version_obligations`, unique `version_id`)
- `VersionObligation` 1—\* `VersionObligationResponse`; `VersionInvitation` 1—1 `VersionObligationResponse` (via `version_obligation_responses`, unique `version_invitation_id`)
- `Version` 1—\* `VersionInvitationRequest` (via `version_invitation_requests`, unique `version_id`+`teacher_id`; approval writes the corresponding `VersionInvitation` row — see §5.8)
- `Candidate` 1—\* `CandidateUploadFile` (via `candidate_upload_files`, one row per fulfilled `VersionUploadFile` slot — see §5.2)

---

## 3. Event

An Event is a periodic, generally annual, series of activities sponsored by an Organization that results in the creation of one or more Ensembles. The Event holds the comparatively stable definition of the program; year-to-year variation lives on the Version.

### 3.1 Event status

Event status is an enum. Default is `sandbox`.

| Status | Meaning |
|---|---|
| `sandbox` | The Event Manager is configuring the Event and its current Version: adjudication and scoring rules, dates, membership eligibility and invitations, candidate eligibility, ensemble definition. Not public. |
| `active` | The current Version is public to invited members; non-invited teachers may request an invitation. |
| `inactive` | The Event is withdrawn but not closed (e.g., COVID). |
| `closed` | The Event is closed. |

**Transitions.** Sandbox is the initial value while the Event/Version is configured; it can transition to any other status, but no status returns to sandbox. Active releases the Event/Version for teacher input and can transition to inactive or closed. Inactive closes teacher input indefinitely and can transition to active or closed. Closed marks the end of input by all actors and can transition back to active.

### 3.2 Event properties (worked reference table)

This is the reference format. Types below are proposed; confirm the flagged ones. `id` and timestamps follow Laravel defaults. The boolean rows below capture per-Event data-collection requirements.

| Column                      | Type             | Null | Default | Notes                                     |
|-----------------------------|------------------|------|---------|-------------------------------------------|
| `id`                        | bigIncrements    | no   | —       | PK                                        |
| `organization_id`           | foreignId        | no   | —       | FK → organizations; indexed               |
| `name`                      | string           | no   | —       |                                           |
| `short_name`                | string           | yes  | null    | Display abbreviation                      |
| `logo_url`                  | string           | yes  | null    | S3 / signed URL target                    |
| `logo_alt`                  | string           | yes  | null    | Accessibility alt text                    |
| `status`                    | enum             | no   | sandbox | EventStatus enum (§3.1)                   |
| `frequency`                 | enum             | no   | annual  | annual \| biannual \| biennial \| monthly |
| `audition_count`            | unsignedSmallInt | no   | 1       | Cap on auditions available to a Candidate |
| `ensemble_count`            | unsignedSmallInt | no   | 1       | Cap on ensembles created by the Event     |
| `created_at` / `updated_at` | timestamp        | yes  | null    | Laravel timestamps                        |
| `deleted_at`                | timestamp        | yes  | null    | Soft delete                               |

### 3.3 Event Grades

The eligible Candidate grades an Event will accept into its Ensemble(s).

| Column     | Notes       |
|------------|-------------|
| `id`       | PK          |
| `event_id` | FK → events |
| `grade`    | smallint    |

---

## 4. Ensembles

An Ensemble is a choir produced as the output of a Version. Ensembles are owned by the Event and re-populated each Version.

### 4.1 Ensemble

| Column         | Notes       |
|----------------|-------------|
| `id`           | PK          |
| `event_id`     | FK → events |
| `name`         |             |
| `short_name`   |             |
| `abbreviation` |             |
| `deleted_at`   | Soft delete |

### 4.2 Ensemble Grades

| Column        | Notes                            |
|---------------|----------------------------------|
| `id`          | PK                               |
| `ensemble_id` | FK → ensembles                   |
| `grade`       | Eligible grade for this Ensemble |

### 4.3 Voice Parts

| Column                      | Notes                                               |
|-----------------------------|-----------------------------------------------------|
| `id`                        | PK                                                  |
| `name`                      | Text description (e.g., soprano, alto, tenor, bass) |
| `abbr`                      | Text abbreviation (e.g., sop, alt, ten, bas)        |
| `sort_order`                | smallint to order the rows in score order           |
| `created_at` / `updated_at` | Laravel timestamps                                  |

### 4.4 Ensemble Voice Parts

| Column          | Notes            |
|-----------------|------------------|
| `id`            | PK               |
| `ensemble_id`   | FK → ensembles   |
| `voice_part_id` | FK → voice_parts |

The pivot carries no ordering column of its own by design. `Ensemble::voiceParts()` must always return its collection ordered by `voice_parts.sort_order` — the pivot's role is membership only, not sequencing.

---

## 5. Version

A Version is the periodic iteration of an Event. It contains the rules and configurations needed to run that occurrence in a systematic, predictable manner. Most cross-year variation lives here.

### 5.1 Version properties (worked reference table)

This is the reference format. Types below are proposed; confirm the flagged ones. `id` and timestamps follow Laravel defaults. The boolean rows below capture per-Event data-collection requirements.

| Column                         | Type                | Null | Default | Notes                                               |
|--------------------------------|---------------------|------|---------|-----------------------------------------------------|
| `id`                           | bigIncrements       | no   | —       | PK                                                  |
| `event_id`                     | foreignId           | no   | —       | FK → events; indexed                                |
| `name`                         | string              | no   | —       |                                                     |
| `short_name`                   | string              | yes  | null    | Display abbreviation                                |
| `senior_class_of`              | unsignedInteger     | no   | 2022    | Year of version's senior class                      |
| `status`                       | enum                | no   | sandbox | EventStatus enum (§3.1)                             |
| `application_type`             | enum                | no   | pdf     | eapplication, pdf                                   |
| `audition_timeslot`            | unsignedTinyInt     | yes  | 20      | for in_person auditions, sets the audition timeslot |
| `audition_type`                | enum                | no   | remote  | in_person, remote                                   |
| `birthday`                     | boolean             | no   | false   | Event requires candidate's birthday                 |
| `emergency_contact_name`       | boolean             | no   | true    | Event requires emergency contact name               |
| `emergency_contact_cell`       | boolean             | no   | true    | Event requires emergency contact cell phone         |
| `emergency_contact_email`      | boolean             | no   | false   | Event requires emergency contact email              |
| `height`                       | boolean             | no   | false   | Event requires candidate's height                   |
| `home_address`                 | boolean             | no   | false   | Event requires candidate's home address             |
| `judge_count`                  | unsignedTinyInteger | no   | 1       | Count of judges per room                            |
| `max_registrants`              | unsignedInteger     | yes  | null    | Cap on total Candidates                             |
| `max_upper_voice_registrants`  | unsignedInteger     | yes  | null    | Cap on upper-voice Candidates                       |
| `pitch_file_visibility`        | enum                | no   | both    | both, candidate, teacher                            |
| `release_confidential_results` | boolean             | no   | false   | Release all confidential results to teachers        |
| `score_order`                  | enum                | no   | asc     | asc, desc                                           |
| `shirt_size`                   | boolean             | no   | false   | Event requires candidate's shirt size               |
| `teacher_cell`                 | boolean             | no   | true    | Event requires teacher's cell phone                 |
| `upload_type`                  | enum                | no   | none    | audio, none, video                                  |
| `created_at` / `updated_at`    | timestamp           | yes  | null    | Laravel timestamps                                  |
| `deleted_at`                   | timestamp           | yes  | null    | Soft delete                                         |


### 5.2 Configuration child tables

Rather than many wide columns on `versions`, configuration is grouped into child tables. The originals listed below map to these tables:

**Adjudication rules** → `version_adjudication` (and related)

- Number of rooms, number of judges per room, judge types.
- **Judge types:** head judge, judge 2, judge 3, judge 4, judge monitor, monitor.
- **Scoring categories:** `version_id`, `description`, `order_by`, timestamps.
- **Scoring factors:** `version_id`, `category_id`, `factor`, `abbreviation`, `best`, `worst`, `interval_by`, `multiplier`, `tolerance`, `order_by`, timestamps.
- Note: tolerance is currently applied at the **room level**; the scoring-factor `tolerance` column is future-proofing only at this point.
- Room definitions (`score_category_id`(s), `voice_part_id`(s)), room tolerances, judge assignments.

**Milestone dates** → `version_dates` (one row per Version per date type, using `start_at` / `end_at`)

Date types: 
- admin: Timestamp that users named in version_roles can access the system.  end_at is null.
- teacher: Timestamp that teachers can access the candidate registration pages for the version. end_at is null.  Access to registration pages is also controlled by version and other date events; ex. when final_teacher_changes is met, the teacher loses the ability to change any candidate registration data.
- candidate: Timestamp that candidates can access version registration pages on StudentFolder.info. Timestamps are used for both start_at and end_at values. Certain registration pages (ex. pitch files and payment) will be controlled by other timestamps, remaining available to candidates after the access to change the registration pages has ended.
- final_teacher_changes: Timestamp after which teacher changes to candidate registration pages is disabled.  end_at date is null.
- adjudication: Timestamps to define the adjudication period.  After the start_at timestamp, named judges for the version can access the adjudication pages.  Access to the adjudication pages ends at end_at timestamp.
- tab_room: Timestamp to open access to the tab room pages for named tab room managers.  end_at is null. Access to the tab room pages ends when the version status is "closed". 
- participation_fee: Timestamp to open access to payment by accepted candidates for ensemble participation fees. start_at and end_at define the ability to execute payment via StudentFolder.info and TheDirectorsRoom.com.
- rehearsal: Timestamp to give the named version rehearsal manager(s) access to the rehearsal pages. start_at should be no later than when the version closes.  end_at should be no later than when the performance occurrs. 
- postmark_deadline: Advisory timestamp to warn teachers when any physical packets must be postmarked by.

**`version_dates` table** — current implementation:

| Column                      | Type            | Notes                                                                                                                     |
|-----------------------------|-----------------|---------------------------------------------------------------------------------------------------------------------------|
| `id`                        | bigIncrements   | PK                                                                                                                        |
| `version_id`                | foreignId       | fk                                                                                                                        |
| `date_type`                 | enum            | admin, teacher, candidate, final_teacher_changes, adjudication, tab_room, participation_fee, rehearsal, postmark_deadline |
| `start_at`                  | Timestamp       | Laravel timestamp                                                                                                         |
| `end_at`                    | Timestamp       | Laravel timestamp, nullable, default = null                                                                               |
| `created_at` / `updated_at` | timestamp       | Laravel timestamps                                                                                                        |


**Fees** → `version_fees`

**`version_fees` table** — next-phase implementation, to identify actual version fees to be charged:

| Column                      | Type            | Notes                  |
|-----------------------------|-----------------|------------------------|
| `id`                        | bigIncrements   | PK                     |
| `version_id`                | foreignId       | fk                     |
| `registration`              | unsignedInteger | in cents, default 2000 |
| `on_site_registration`      | unsignedInteger | in cents, default 0    |
| `participation`             | unsignedInteger | in cents, default 0    |
| `epayment_surcharge`        | unsignedInteger | in cents, default 0    |
| `housing`                   | unsignedInteger | in cents, default 0    |
| `created_at` / `updated_at` | timestamp       | Laravel timestamps     |


**E-payment credentials** → `epayment_credentials`

**`epayment_credentials` table** — next-phase implementation, to epayment vendor credentials

| Column                      | Type            | Notes                             |
|-----------------------------|-----------------|-----------------------------------|
| `id`                        | bigIncrements   | PK                                |
| `version_id`                | foreignId       | fk                                |
| `epayment_id`               | string          | typically email address           |
| `secret`                    | string          | encrypted, nullable, default null |
| `created_at` / `updated_at` | timestamp       | Laravel timestamps                |

**`version_membership_requirements` table** — current implementation

| Column                      | Type          | Notes                  |
|-----------------------------|---------------|------------------------|
| `id`                        | bigIncrements | PK                     |
| `version_id`                | foreignId     | fk                     |
| `membership_card`           | boolean       | default false          |
| `valid_thru`                | date          | nullable, default null |
| `created_at` / `updated_at` | timestamp     | Laravel timestamps     |

**`version_counties` table** — current implementation

| Column                      | Type          | Notes                  |
|-----------------------------|---------------|------------------------|
| `id`                        | bigIncrements | PK                     |
| `version_id`                | foreignId     | fk                     |
| `county_id`                 | foreignId     |                        |
| `created_at` / `updated_at` | timestamp     | Laravel timestamps     |

**`version_ensemble_order` table** — current implementation

| Column                      | Type            | Notes              |
|-----------------------------|-----------------|--------------------|
| `id`                        | bigIncrements   | PK                 |
| `version_id`                | foreignId       | fk                 |
| `ensemble_id`               | foreignId       | fk                 |
| `order_by`                  | unsignedTinyInt | default 1          |
| `created_at` / `updated_at` | timestamp       | Laravel timestamps |

**Version timeslots (if in-person)** → `version_timeslots` (`start_at`, `end_at`, duration, break count)

**`version_timeslots` table** — next-phase implementation:

| Column                      | Type          | Notes              |
|-----------------------------|---------------|--------------------|
| `id`                        | bigIncrements | PK                 |
| `version_id`                | foreignId     | fk                 |
| `school_id`                 | foreignId     | fk                 |
| `timeslot`                  | timestamp     | Laravel timestamp  |
| `created_at` / `updated_at` | timestamp     | Laravel timestamps |

**Expected upload files (if `audition_type` is remote)** → `version_upload_files`

**`version_upload_files` table** — current implementation, the generic, ordered list of files a Candidate is expected to upload (e.g., scales, solo, quintet) when `audition_type` is `remote`:

| Column                      | Type            | Notes                                          |
|-----------------------------|-----------------|-------------------------------------------------|
| `id`                        | bigIncrements   | PK                                              |
| `version_id`                | foreignId       | FK → versions; cascade on delete                |
| `name`                      | string          | Generic file label (e.g., "scales", "solo")     |
| `order_by`                  | unsignedTinyInt | default 1                                       |
| `created_at` / `updated_at` | timestamp       | Laravel timestamps                              |

`Version::uploadFiles()` is a `HasMany` ordered by `order_by`. There is no stored `upload_file_count` column — `Version::upload_file_count` is a derived accessor (`count($this->uploadFiles)`), so the count can never drift from the list of names.

**Other Version configuration (column or small table as appropriate):**

- **Audition type:** in-person \| remote.
- **Membership requirements:** organization id, membership card, `valid_thru` date.
- **Application type:** eapplication \| pdf (enum).
- **Eligible counties:** if applicable.
- **County assignments:** if multiple co-registration managers.
- **Event ensemble order:** if multiple Ensembles share the same candidate pool (drives cascading / alternating assignment).
- **Default invitations:** auto-invite current Organization members.
- **Pitch files & visibility:** audience (teacher \| candidate \| both); visible to candidates post-candidate-close; visible to teachers post-teacher-close; visible to teachers post-version-close. Event Manager CRUD is built — see §5.5. The post-close visibility windows are not yet enforced (flagged in §5.5).
- **Score order:** ascending (golf; lower is better) or descending (bowling; higher is better).

**`candidates` table** — next-phase implementation, to identify a candidate:

| Column                      | Type                | Notes                                                                                                                                                          |
|-----------------------------|---------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `id`                        | unsignedBigInteger  | PK (not auto-increment); set by observer: concatenation of version_id + zero-padded random 4-digit suffix (1000–9999) unique within the version, e.g. 421357  |
| `ref`                       | string              | Human-readable display id; set by observer from id, hyphen inserted after version prefix, e.g. "42-1357"; unique                                               |
| `student_id`                | foreignId           | fk                                                                                                                                                             |
| `version_id`                | foreignId           | fk                                                                                                                                                             |
| `school_id`                 | foreignId           | fk                                                                                                                                                             |
| `teacher_id`                | foreignId           | fk                                                                                                                                                             |
| `voice_part_id`             | foreignId           | fk                                                                                                                                                             |
| `status`                    | enum                | eligible, pending, registered, withdrew, teacher_withdrawn, no_show, adjudicated, incomplete, accepted, not_accepted, declined, removed                        |
| `program_name`              | string              | How the candidate wants their name listed in the program; not nullable; defaults to users.name at enrollment                                                   |
| `emergency_contact_id`      | foreignId           | fk                                                                                                                                                             |
| `created_at` / `updated_at` | timestamp           | Laravel timestamps                                                                                                                                             |

**`emergency_contacts` table** — current implementation (student-level, pre-dates this spec):

| Column                      | Type          | Notes                                                                                                                                                                |
|-----------------------------|---------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `id`                        | bigIncrements | PK                                                                                                                                                                    |
| `student_id`                | foreignId     | FK → students                                                                                                                                                        |
| `name`                      | string        | Emergency contact name                                                                                                                                              |
| `relationship`              | enum          | `EmergencyContactRelationship`: mother, father, step_mother, step_father, grandmother, grandfather, guardian, sibling, aunt, uncle, guardian_mother, guardian_father, foster_mother, foster_father, other |
| `email`                     | string        | Emergency contact email                                                                                                                                             |
| `cell_phone`                | string(20)    | Normalized on set via `PhoneNormalizer`                                                                                                                             |
| `home_phone`                | string(20)    | Nullable; normalized on set via `PhoneNormalizer`                                                                                                                   |
| `work_phone`                | string(20)    | Nullable; normalized on set via `PhoneNormalizer`                                                                                                                   |
| `best_phone`                | enum          | `BestPhone`: cell, home, work — default `cell`                                                                                                                      |
| `created_at` / `updated_at` | timestamp     | Laravel timestamps                                                                                                                                                  |
| `deleted_at`                | timestamp     | Soft delete                                                                                                                                                          |


**`candidate_status_history` table** — current implementation, immutable audit log of every candidate status transition:

| Column         | Type          | Notes                                              |
|----------------|---------------|----------------------------------------------------|
| `id`           | bigIncrements | PK                                                 |
| `candidate_id` | foreignId     | FK → candidates                                    |
| `from_status`  | enum          | nullable — null on initial enrollment              |
| `to_status`    | enum          | the new status                                     |
| `user_id`      | foreignId     | FK → users; who triggered the change               |
| `notes`        | text          | nullable — admin override reason, reconciliation   |
| `created_at`   | timestamp     | when the transition occurred; no `updated_at`      |

**`audition_results` table** — next-phase implementation, to carry results forward:

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | PK |
| `candidate_id` | foreignId | fk |
| `version_id` | foreignId | fk |
| `voice_part_id` | foreignId | fk |
| `school_id` | foreignId | fk |
| `voice_part_order_by` | unsignedSmallInt | |
| `score_count` | unsignedSmallInt | |
| `total` | unsignedMediumInt | |
| `accepted` | boolean | |
| `accepted_ensemble_id` | foreignId | fk, nullable |
| `created_at` / `updated_at` | timestamp | Laravel timestamps |

**`candidate_upload_files` table** — designed 2026-07-16, not yet built; fulfills a `version_upload_files` (§5.2) slot with an actual Candidate-uploaded recording, per the Registration workflow (§6.2):

| Column                       | Type          | Null | Default | Notes                                                                                   |
|-------------------------------|---------------|------|---------|--------------------------------------------------------------------------------------------|
| `id`                           | bigIncrements | no   | —       | PK                                                                                           |
| `candidate_id`                 | foreignId     | no   | —       | FK → candidates; cascade on delete                                                           |
| `version_upload_file_id`       | foreignId     | no   | —       | FK → version_upload_files; cascade on delete — which expected slot ("scales", "solo", …) this fulfills |
| `url`                          | string        | no   | —       | S3 object key, `candidateUploads/{candidate_id}/...`, same disk convention as pitch files    |
| `status`                       | string        | no   | pending | `CandidateUploadStatus` (§8.12): `pending \| approved` — only two states; a rejection deletes the row (see below), it is never a stored third state |
| `uploaded_at`                  | timestamp     | no   | —       | Set when the Candidate/teacher uploads the file                                              |
| `decided_at`                   | timestamp     | yes  | null    | Set when the teacher approves it                                                             |
| `decided_by_user_id`           | foreignId     | yes  | null    | FK → users; null on delete; the (co-)teacher who approved it                                 |
| `created_at` / `updated_at`    | timestamp     | yes  | null    | Laravel timestamps                                                                            |

Unique on (`candidate_id`, `version_upload_file_id`) — one active upload per Candidate per slot. **Reject deletes, it does not transition to a "rejected" status**: per the source design doc, "the recording is removed from the candidate's record and another recording can be uploaded" — this matches the existing delete-on-remove precedent from Version Pitch Files (§5.5), including deleting the underlying S3 object. Replacing an approved file (teacher re-opens it for re-upload) should also revert `status` to `pending` and clear `decided_at`/`decided_by_user_id`, consistent with `version_pitch_files`' replace-deletes-previous-S3-object behavior.

**Registration-gate mapping (§6.2).** "Upload of required audio/video files" is satisfied when the Candidate has a `candidate_upload_files` row (any status) for every `version_upload_files` slot on that Version. "Teacher approval of required audio/video files" is satisfied when every one of those rows has `status = approved`. Both milestone items only apply when the Version's `audition_type` is `remote` (§5.1).

**Voice-part invariant.** `candidates.voice_part_id` is the single source of truth for a Candidate's voice part — no upload, application, or pitch-file record duplicates or overrides it. This mirrors the rule already in place for §5.7's Candidate Application (`CandidateApplicationData` resolves `voicePartName` from the live Candidate record, never from a stored copy).

### 5.3 Version roles (version-scoped)

Roles are granted relative to the Version via Spatie. The defined roles are:

- Event Manager
- Registration Manager
- Co-Registration Manager
- Web Registration Manager
- Tab Room Manager
- Rehearsal Manager

**Co-Registration Manager delegation (built 2026-08-04).** Assigning Co-Registration Manager is deliberately split out of the generic Configure → Roles tab (Event Manager only) into its own screen, `VersionCoRegistrationManagers` (route `events.versions.co-registration-managers`), reachable by Founder, Event-wide Event Manager, **or a Registration Manager held specifically on that Version** — gated by `VersionRoleAssignmentService::canManageCoRegistrationManagers()`. This lets a Registration Manager build out their own support team without exposing the rest of Configuration (dates, fees, obligations, other roles). Deliberately narrower than `canManageAuditionEnvironment()` (§5.9): a Co-Registration Manager cannot delegate the role further. Every Co-Registration Manager assignment is paired with a set of county responsibilities (`co_registration_manager_counties`: `version_id`, `user_id`, `county_id`), restricted to a subset of the Version's own `version_counties` and mutually exclusive across managers on the same Version (enforced by a unique constraint on `(version_id, county_id)`). `VersionRoleAssignmentService::assignCoRegistrationManager()`/`revokeCoRegistrationManager()` write the role and county rows atomically; the generic `assignRole()`/`revokeRole()` path now rejects `Co-Registration Manager` outright (400) so the role can never be granted without a county assignment.

### 5.4 Version Invitations (Event Manager controls who may participate)

The Event Manager builds and maintains the invitation list for a Version: the set of teachers permitted to enroll Candidates. Confirmed 2026-07-07.

**Eligibility pool (computed, not stored).** A teacher is eligible for a Version if they have at least one active + verified school (`Teacher::hasActiveSchool()`) **and** satisfy at least one of:

- **County leg:** any of the teacher's active + verified schools has a `county_id` present in that Version's `version_counties`. If the Version has **zero** `version_counties` rows, the county leg is unrestricted — it passes for every active+verified teacher (the Version isn't geographically scoped).
- **Membership leg:** the teacher has **any** `Membership` row (regardless of `membership_expires_at`) for the Version's Event's root organization (`Organization::membershipOrganization()`). Expired memberships still satisfy this leg; `membership_expires_at` is displayed for the Event Manager's own judgment, not enforced as a hard gate here.

This mirrors the existing `EligibilityService` pattern (computed pool, not a persisted table) — implement as a new `VersionInvitationEligibilityService`, not by pre-seeding rows.

**`version_invitations` table** — new, this phase:

| Column                | Type          | Null | Default | Notes                                                                                     |
|------------------------|---------------|------|---------|---------------------------------------------------------------------------------------------|
| `id`                   | bigIncrements | no   | —       | PK                                                                                           |
| `version_id`           | foreignId     | no   | —       | FK → versions; cascade on delete                                                             |
| `teacher_id`           | foreignId     | no   | —       | FK → teachers; cascade on delete                                                             |
| `status`               | enum          | no   | invited | `VersionInvitationStatus` (§8.8) — `invited \| obligated \| participating`. No `eligible` case: absence of a row **is** "eligible" (see below). |
| `invited_at`           | timestamp     | no   | —       | Set when the row is created                                                                  |
| `invited_by_user_id`   | foreignId     | no   | —       | FK → users; who performed the invite                                                         |
| `created_at`/`updated_at` | timestamp  | yes  | null    | Laravel timestamps                                                                            |

Unique on (`version_id`, `teacher_id`).

**Status derivation for display.** A teacher's displayed status is: no `version_invitations` row → `eligible`; row exists → the row's `status` column (`invited` by default at creation). `obligated` and `participating` are reserved enum cases — this phase does not set them. They will be written by the not-yet-built registration workflow (a teacher reaches `obligated` by agreeing to Version obligations during registration; `participating` follows from there). Do not scaffold that trigger now.

**Invite / uninvite mechanics.**

- Checking a teacher's checkbox and saving creates a `version_invitations` row (`status = invited`, `invited_at = now()`, `invited_by_user_id = auth()->id()`).
- Unchecking an already-invited teacher deletes their row, reverting them to computed `eligible`.
- **Invite All** creates rows for every currently-listed teacher with no existing row.
- **Remove All** deletes rows for every currently-listed teacher whose status is `invited`.
- **Guard:** attempting to uninvite (individually or via Remove All) a teacher whose row status is `obligated` or `participating` must be blocked with an explanation, not silently skipped or hidden — consistent with this project's guarded-action-visibility convention. This guard has no live trigger yet (those statuses aren't reachable this phase) but must be implemented now so the future registration workflow doesn't have to retrofit it.
- No email/notification is sent on invite in this phase — status is recorded only.

**Roster display.** One row per teacher (not per school). Columns: teacher name, email, school name (county), `membership_expires_at`, status.

- School/county shown: among the teacher's active + verified schools, prefer the first (alphabetical by school name) whose county is in the Version's `version_counties` list; if the teacher qualifies only via the membership leg (no county match), fall back to their first active+verified school alphabetically.
- `membership_expires_at` shown: the latest (`max`) value across the teacher's `Membership` rows for the Version's root organization; blank if the teacher has no membership record (i.e., they qualified via the county leg only).

**Authorization.** Gate the invitation-management screen the same way as `VersionEdit`: `VersionRoleAssignmentService::canManageEvent($user, $version->event)` (Founder or Event Manager on any Version of the Event).

**Not built this phase (flag, don't scaffold):** search/filter/pagination on the roster (revisit if a real organization's teacher roster proves too large for a single page), email notification on invite, and any UI for the `obligated`/`participating` transitions themselves.

### 5.5 Version Pitch Files (Event Manager maintains the audio/reference library)

The Event Manager maintains an ordered, per-voice-part library of pitch files (audio/video/PDF reference material — e.g., scales, solo excerpts, quintet mixes) for a Version. Confirmed 2026-07-09.

**`version_pitch_files` table** — current implementation:

| Column                      | Type              | Null | Default | Notes                                                          |
|------------------------------|-------------------|------|---------|-----------------------------------------------------------------|
| `id`                          | bigIncrements     | no   | —       | PK                                                               |
| `version_id`                  | foreignId         | no   | —       | FK → versions; cascade on delete                                 |
| `voice_part_id`               | foreignId         | no   | —       | FK → voice_parts; cascade on delete                              |
| `name`                        | string            | no   | —       | File label (e.g., "scales", "solo")                              |
| `description`                 | string            | yes  | null    |                                                                   |
| `url`                         | string            | no   | —       | S3 object key (`pitchFiles/{version_id}/...`)                    |
| `order_by`                    | unsignedSmallInt  | no   | 1       | Display order; resequenced on reorder/save-order                 |
| `created_at` / `updated_at`   | timestamp         | yes  | null    | Laravel timestamps                                                |

`Version::pitchFiles()` is a `HasMany` ordered by `order_by`. Uploaded files accept `mp3, wav, m4a, pdf, mp4, mov` (max 50 MB) and are stored on the `s3` disk under `pitchFiles/{version_id}/`; replacing a file on edit deletes the previous S3 object, and removing a row deletes its S3 object too.

**Voice part scoping.** `voice_part_id` must be one of the Version's `availableVoiceParts()` (the union of voice parts across the Event's Ensembles) — a pitch file cannot be assigned to a voice part the Event doesn't use. `order_by` is assigned per-Version (not per-voice-part) as `max(order_by) + 1` on create.

**Roster UI.** `VersionPitchFiles` Livewire component (`/events/versions/{version}/pitch-files`, route `events.versions.pitch-files`) provides: search (name/description), filter by voice part and by file-name type, sortable columns, drag-and-drop reorder (native HTML5 drag-and-drop per [[project_livewire_wire_sort]] — Livewire's `wire:sort` is broken on Flux table rows), and a bulk "save order" fallback via numeric inputs. Add/edit is a modal form; both create and edit go through the same `save()` action.

**Authorization.** Gated identically to `VersionEdit` and `VersionInvitations`: `VersionRoleAssignmentService::canManageEvent($user, $version->event)` (Founder or Event Manager on any Version of the Event) on mount and on every mutating action (`save`, `remove`, `saveOrder`, `reorderPitchFiles`).

**Not built this phase (flag, don't scaffold):** enforcement of `pitch_file_visibility` (§5.1) and the post-close visibility windows described in the bullet above — the column and enum (`PitchFileVisibility`: `both | candidate | teacher`) exist and are configurable on `VersionEdit`, but nothing yet reads them to gate what a teacher or candidate can see. Candidate/teacher-facing display of pitch files (StudentFolder.info side) is not built — this phase is Event Manager CRUD only.

### 5.6 Version Obligations (Event Manager authors, teacher accepts/rejects)

The Event Manager authors a per-Version "Teacher Obligations" document (rich text, previously hand-built per organization and emailed to a developer) and publishes it; an invited teacher views it and accepts or rejects, which feeds the `VersionInvitationStatus::Obligated` case reserved in §8.8. Built 2026-07-09, as the pilot for a broader "Event Manager self-serve content" strategy (Candidate Application and Estimate Form/Invoice are the next two candidates for the same pattern, not yet built).

**`version_obligations` table** — current implementation:

| Column                       | Type          | Null | Default | Notes                                                               |
|-------------------------------|---------------|------|---------|----------------------------------------------------------------------|
| `id`                           | bigIncrements | no   | —       | PK                                                                    |
| `version_id`                   | foreignId     | no   | —       | FK → versions; cascade on delete; **unique** (one row per Version)   |
| `title`                        | string        | yes  | null    | Optional display title                                               |
| `body`                         | longText      | no   | —       | Sanitized HTML (see below); rendered via `{!! !!}`                   |
| `status`                       | string        | no   | draft   | `VersionObligationStatus`: `draft \| published`                      |
| `published_at`                 | timestamp     | yes  | null    | Set when published                                                    |
| `published_by_user_id`         | foreignId     | yes  | null    | FK → users; null on delete                                            |
| `created_at` / `updated_at`    | timestamp     | yes  | null    | Laravel timestamps                                                    |

**`version_obligation_responses` table** — current implementation:

| Column                         | Type          | Null | Default | Notes                                                                 |
|----------------------------------|---------------|------|---------|-------------------------------------------------------------------------|
| `id`                              | bigIncrements | no   | —       | PK                                                                       |
| `version_invitation_id`           | foreignId     | no   | —       | FK → version_invitations; cascade on delete; **unique** (one response per invitation, toggled in place) |
| `version_obligation_id`           | foreignId     | no   | —       | FK → version_obligations; cascade on delete                             |
| `decision`                        | string        | no   | —       | `ObligationDecision`: `accepted \| rejected`                            |
| `decided_at`                      | timestamp     | no   | —       | Updated on every toggle                                                 |
| `obligation_snapshot`             | longText      | no   | —       | Frozen copy of the merged `body` at decision time — the audit record of exactly what the teacher agreed to, independent of later edits |
| `created_at` / `updated_at`       | timestamp     | yes  | null    | Laravel timestamps                                                       |

**Authoring & sanitization.** The Event Manager edits `title`/`body` via a `flux:editor` (TipTap-based) on the `VersionEdit` "Obligations" tab, toolbar restricted to `heading bold italic underline | bullet ordered blockquote | link`. `body` is passed through `mews/purifier`'s `obligations` HTMLPurifier profile (`config/purifier.php`) on every save via `VersionObligationObserver::saving()` — allowlist `p,h1,h2,h3,strong,b,em,i,u,ul,ol,li,blockquote,a[href]`, no `img`/`style`/`class`/`div`/`span`, unsafe URI schemes (e.g. `javascript:`) stripped. This runs regardless of entry point (Livewire, tinker, future tooling), not just the one form.

**Merge fields.** `body` may contain literal tokens `{{versionShortName}}` / `{{versionName}}`, resolved by plain `str_replace` (not Blade evaluation — the Event Manager's content must never be passed through a Blade compile step) at render time on both the admin preview intent and the teacher-facing page, and frozen into `obligation_snapshot` at accept/reject time.

**Publish workflow.** `status` starts `draft`. **Save** persists `title`/`body` without touching `status`. **Publish** persists and sets `status = published`, `published_at = now()`, `published_by_user_id = auth()->id()` in one action. **Unpublish** reverts to `draft` (`wire:confirm`-gated) — existing teacher responses are untouched (their `obligation_snapshot` remains the audit record regardless of later publish state changes). Teachers only ever see a `published` obligation; `draft` is invisible to them.

**Teacher accept/reject.** `App\Livewire\Registrations\VersionObligations` (`/registrations/{version}/obligations`, route `registrations.obligations`) shows the published obligation (merge-resolved) to an invited teacher and lets them Accept or Reject via `updateOrCreate` keyed on the unique `version_invitation_id` — toggling updates the same row rather than accumulating history. `VersionObligationResponseObserver::saved()` flips the parent `VersionInvitation.status` to `VersionInvitationStatus::Obligated` (§8.8, previously reserved and unwritten) when the current decision is `accepted`.

**Rejection is an iron gate (resolved 2026-07-10, was an open question above).** On a `rejected` decision, the observer sets `VersionInvitation.status = VersionInvitationStatus::Rejected` (new case) and calls `CandidateService::withdrawAllForTeacherVersion()`, which bulk-withdraws (`CandidateStatus::TeacherWithdrawn`) every candidate that teacher has enrolled for that Version still in an active state (`Eligible`/`Pending`/`Registered`) — full audit trail via the existing `CandidateObserver` history hook. `EligibilityService::isBlockedByRejectedObligations()` then blocks all new enrollment for that teacher+Version (`eligibleStudents()` returns empty, `VersionDashboard::enroll()` refuses with a form error) until they accept again. **This is reversible**: re-accepting flips the invitation back to `Obligated` and lifts the enrollment block (existing code path, unconditional on decision), but does **not** resurrect candidates withdrawn during the rejection window — those need to be re-enrolled by hand. Both `Registrations/VersionDashboard` and `Registrations/VersionObligations` show a persistent red callout while the gate is active.

**Guard activation.** `VersionInvitations::uninvite()` (§5.4) already contained a guard — `in_array($rawStatus, [Obligated, Participating])` blocks removing a teacher who has agreed to obligations — written before this feature existed. This phase is what makes that guard live for the first time.

**Authorization.** Authoring is gated identically to the rest of `VersionEdit` (`VersionRoleAssignmentService::canManageEvent`), checked once at `mount()` per the existing `VersionEdit` convention (not re-checked per action). The teacher-facing page scopes by the authenticated teacher's own `VersionInvitation` row (404 if not invited); no separate role check.

**Not built this phase (flag, don't scaffold):** PDF rendition of the obligations (a disabled placeholder button exists on the teacher page; `barryvdh/laravel-dompdf`'s first real usage ended up being §5.7's Candidate Application, not this feature); an "insert merge field" toolbar button (Event Manager must type the token literally); any consequence for a `rejected` decision beyond logging it.

---

### 5.7 Candidate Application (Event Manager authors, teacher certifies signatures, real PDF)

The Event Manager authors the per-Version audition/participation application document — the actual form students, parents, and teachers sign — and a teacher certifies signatures per candidate. Built 2026-07-10 as the second "Event Manager self-serve content" feature (see §5.6's intro), extending the pattern to three authored bodies instead of one, per-*candidate* (not per-teacher) state, a much larger merge-token catalog, and this codebase's first real `barryvdh/laravel-dompdf` usage. Scoped from three real legacy templates (`docs/sample_applications/event_id-{1,9,25}_application_pdf.blade.php`).

Two modes already existed in the schema before this feature (`App\Enums\ApplicationType`, `versions.application_type`, defaulting to `Pdf`):
- **`Pdf`** — a printed legal document. Physical ink signatures required from Candidate, Parent, Teacher, and Principal. The Teacher additionally checks **one** box in-app: "I certify these signatures are present, complete, and have integrity."
- **`EApplication`** — digital signatures required from Candidate and Parent, PDF offered only as an optional convenience copy. Because there is no Student-facing portal yet (StudentFolder.info) and no Parent/Guardian account type at all, the **Teacher** checks two boxes on their behalf — "Candidate signed" / "Parent signed" — and certification is simply both being checked (no separate third action). Explicit stopgap: once a Student portal exists, students/parents will do this themselves.

**`version_applications` table** — one row per Version:

| Column                                | Type          | Null | Default | Notes                                                                 |
|----------------------------------------|---------------|------|---------|--------------------------------------------------------------------------|
| `id`                                    | bigIncrements | no   | —       | PK                                                                        |
| `version_id`                            | foreignId     | no   | —       | FK → versions; cascade on delete; **unique** (one row per Version)       |
| `student_endorsement_body`              | longText      | no   | —       | Sanitized HTML, same profile as Obligations                              |
| `parent_endorsement_body`                | longText      | no   | —       | Sanitized HTML                                                            |
| `teacher_principal_endorsement_body`     | longText      | yes  | null    | Sanitized HTML; only used/required in `Pdf` mode — event 25's real `EApplication` template has no such section at all |
| `status`                                | string        | no   | draft   | `VersionApplicationStatus`: `draft \| published`                         |
| `published_at`                          | timestamp     | yes  | null    | Set when published                                                       |
| `published_by_user_id`                  | foreignId     | yes  | null    | FK → users; null on delete                                                |
| `created_at` / `updated_at`             | timestamp     | yes  | null    | Laravel timestamps                                                       |

No `title` column, unlike Obligations — this document has no freeform admin-authored heading; the header is always auto-rendered from live Version/Event/Organization data.

**New `candidates` columns** (not a separate child table — matches the existing precedent of `program_name`/`emergency_contact_id` living directly on `candidates` as per-candidate registration state):

| Column                                | Type       | Null | Notes                                                                 |
|-----------------------------------------|------------|------|--------------------------------------------------------------------------|
| `application_certified_at`               | timestamp  | yes  | `Pdf`-mode teacher attestation                                            |
| `application_certified_by_user_id`        | foreignId  | yes  | FK → users; null on delete; audit of who certified                       |
| `application_candidate_signed_at`         | timestamp  | yes  | `EApplication`-mode                                                       |
| `application_parent_signed_at`            | timestamp  | yes  | `EApplication`-mode                                                       |

No stored "certified" boolean for `EApplication` mode — `Candidate::is_application_certified` is a computed accessor (`Pdf` → `application_certified_at !== null`; `EApplication` → both signed timestamps non-null), never a persisted redundant flag.

**Authoring & sanitization.** A new "Application" tab on `VersionEdit` (inserted after Requirements, before Obligations) offers three `flux:editor` fields — Student Endorsement and Parent/Guardian Endorsement always, Teacher/Principal Endorsement only when `application_type = Pdf`. All three share the exact same restricted `obligations` HTMLPurifier profile as §5.6 (the toolbar/allowlist is identical, so the profile is deliberately shared rather than duplicated) via `VersionApplicationObserver::saving()`, each body gated on its own `isDirty()` check. The header, candidate-summary table, and fee amounts are **never authored** — always rendered live from real Version/Candidate/Fee/Organization data via `App\Support\CandidateApplicationData`.

**Merge-token resolver.** `App\Support\CandidateApplicationData` is a readonly DTO with two named constructors: `fromCandidate()` (real per-candidate PDF generation — name/grade/voice part/school/teacher/emergency-contact/fees all resolved from that candidate's actual data) and `placeholder()` (admin-authoring Preview — real Version-level data plus fixed sample values for anything candidate-personal, e.g. `'Jane A. Sample'`). `toTokenMap()` feeds `VersionApplication::mergeTokens()`, a generalization of `VersionObligation::mergeTokens()`'s plain `str_replace` approach (still never Blade evaluation) across the full token catalog (`candidateFullName`, `voicePartName`, `grade`, `schoolName`, `schoolShortName`, `teacherFullName`, phone numbers, `emergencyContactName`, fee amounts, `versionShortName`/`versionName`).

**Publish workflow.** Identical shape to Obligations: `saveApplication()`/`publishApplication()`/`unpublishApplication()` on `VersionEdit`. `Pdf`-mode Save/Publish additionally requires the Teacher/Principal body (validation fails otherwise); switching a Version to `EApplication` mode nulls out any previously-authored Teacher/Principal text defensively on next save, so stale text can't linger. Draft is invisible on candidate records, same "draft is invisible" rule as Obligations.

**Teacher certification.** `App\Livewire\Registrations\CandidateDetail` gained instant toggle actions (`toggleApplicationCertified` for `Pdf` mode; `toggleApplicationCandidateSigned`/`toggleApplicationParentSigned` for `EApplication` mode, both independent), each flowing through the existing `CandidateService::recalculateStatus()` path exactly like `saveProgramName`/`saveEmergencyContact` already do. `App\Concerns\HasCandidateChecklist` gained matching checklist item(s), gated on the Application being Published (mirrors §5.6's "unpublished document produces no uncompletable item" rule) — "Signatures certified" for `Pdf`; "Candidate signed" + "Parent signed" for `EApplication`.

**PDF generation — first real `barryvdh/laravel-dompdf` usage.** A shared, mode-agnostic Blade partial (`resources/views/candidate-application/document.blade.php`) is included by both `resources/views/pdf/candidate-application.blade.php` (the dompdf entry view) and the admin Preview modal, so the two can never drift. `App\Http\Controllers\CandidateApplicationPdfController` (single-action invokable, route `registrations.candidate.application-pdf`, `GET /registrations/{version}/{candidate}/application.pdf`) guards candidate/version match (404), requesting-teacher ownership (403), and a Published Application (404), then resolves real per-candidate merge tokens and streams the PDF download. In `EApplication` mode the download is available as soon as the Application is Published, independent of either signature toggle — a convenience copy, not gated on completion.

**Authorization.** Same as Obligations — authoring gated by `VersionRoleAssignmentService::canManageEvent` at `VersionEdit::mount()`; teacher-facing actions scoped to the authenticated teacher's own candidates (existing `CandidateDetail`/`VersionDashboard` ownership checks, unchanged).

**Not built this phase (flag, don't scaffold):** real Student/Parent-facing digital signing (StudentFolder.info) — replaces today's teacher-does-it-all stopgap once that portal exists; verification that `organizations.logo_file_url` / `events.logo_url` are genuinely pre-resolved URLs rather than storage-relative keys (untestable today — all seeded logo columns are blank; first real data will confirm or require a one-line `Storage::disk(...)->url()` wrap); a student-home-phone merge token (legacy had one, current schema has no home-phone concept for a Student/User — intentionally omitted rather than faked).

---

### 5.8 Version Invitation Requests (teacher-initiated, Event Manager approves/denies)

Designed 2026-07-16; built 2026-07-16. Scoped from a source design doc plus a clarifying-question pass with the product owner. This is the teacher-initiated counterpart to §5.4: instead of (or in addition to) the Event Manager building the invitation list top-down, a teacher who is eligible for a Version but has not been invited can *request* an invitation.

**Relationship to `EventInvitationRequest`.** The codebase already has an unrelated, Event-scoped `EventInvitationRequest` (`event_invitation_requests`, `EventInvitationStatus`: `Pending | Approved | Denied`), created today only during `TeacherOnboardingWizard::requestEventInvitations()`. That model represents "let me into this Event/Organization" during onboarding and is **not reused** for this feature — `version_invitation_requests` is a distinct, Version-scoped table, because eligibility, open/close windows, and the resulting `VersionInvitation` row are all Version-scoped concepts, not Event-scoped ones. The two tables intentionally coexist, mirroring the existing precedent of `VersionInvitation` being kept separate from `EventInvitationRequest` (§9 item 8).

**`version_invitation_requests` table** — current implementation:

| Column                | Type          | Null | Default | Notes                                                                                     |
|------------------------|---------------|------|---------|-----------------------------------------------------------------------------------------------|
| `id`                    | bigIncrements | no   | —       | PK                                                                                              |
| `version_id`            | foreignId     | no   | —       | FK → versions; cascade on delete                                                                |
| `teacher_id`            | foreignId     | no   | —       | FK → teachers; cascade on delete                                                                |
| `status`                | string        | no   | pending | `VersionInvitationRequestStatus` (§8.11): `pending \| approved \| denied`                       |
| `requested_at`          | timestamp     | no   | —       | Set when the row is created, and reset on re-request (see below)                                |
| `decided_at`            | timestamp     | yes  | null    | Set when the Event Manager approves or denies                                                    |
| `decided_by_user_id`    | foreignId     | yes  | null    | FK → users; null on delete; who approved/denied — see the email-attribution note below           |
| `created_at`/`updated_at` | timestamp   | yes  | null    | Laravel timestamps                                                                                |

Unique on (`version_id`, `teacher_id`) — one row per teacher per Version, toggled in place (same "update, don't accumulate" pattern as `version_obligation_responses`, §5.6), not a history table.

**Who can request.** A teacher may request an invitation for a Version only if: they are in `VersionInvitationEligibilityService`'s eligible pool (§5.4 — the same OR logic: active+verified school, and either a county match or any org membership, unrestricted if the Version has zero `version_counties` rows); they do **not** already have a `version_invitations` row (already invited — go straight to Registration); and they either have no `version_invitation_requests` row, or their existing row is `status = denied` (a prior denial does not permanently lock them out — re-requesting is allowed and re-uses the same row, resetting `status = pending`, `requested_at = now()`, `decided_at = null`, `decided_by_user_id = null`). A `pending` request cannot be re-submitted (button becomes "Request Sent" / disabled, not hidden — per this project's guarded-action-visibility convention, §5.4).

**Request mechanics.** On request: `updateOrCreate(['version_id', 'teacher_id'], ['status' => Pending, 'requested_at' => now()])`. An email is sent to the Version's Event Manager(s) (everyone holding the Event Manager role on any Version of the Event, per `VersionRoleAssignmentService`) containing the teacher's bona fides — name, school name, county, email, phone number(s), organization membership number, `membership_expires_at` — the same fields already surfaced on the §5.4 invitation roster.

**Approve/deny via signed one-click email links.** Each Event Manager's copy of the notification email (`VersionInvitationRequestSubmittedMail`, `resources/views/mail/version-invitation-request-submitted.blade.php`) contains personalized "Approve" and "Deny" buttons, built as Laravel **signed routes** (`URL::temporarySignedRoute`, `signed` middleware) — no login required to act, since the recipient may not have an active session. Each recipient's link is generated individually in `App\Livewire\Registrations\RequestInvitation::notifyEventManagers()` and encodes both the request id and *that* Event Manager's own user id (route `version-invitation-requests.approve` / `.deny`, params `{versionInvitationRequest}/{user}`), so `decided_by_user_id` is attributable even without `auth()`, rather than a single shared link for all recipients. Because multiple Event Managers may receive the same notification, `App\Http\Controllers\VersionInvitationRequestController` checks the row's current status *before* acting and renders `version-invitation-requests.already-decided` ("Already handled by {name} on {date}") instead of erroring or double-applying the decision — first-click-wins is a real, handled case, not an edge case dismissed as unreachable.

- **Approve:** `VersionInvitationRequestController::approve()` calls `VersionInvitationRequestService::approve()`, which sets `version_invitation_requests.status = approved`, `decided_at = now()`, `decided_by_user_id = <the clicking EM>`, and creates the actual `version_invitations` row via the same shape as an Event-Manager-initiated invite (§5.4): `status = Invited`, `invited_at = now()`, `invited_by_user_id = <the clicking EM>`. `VersionInvitationRequestApprovedMail` is then sent to the teacher confirming the invitation.
- **Deny:** `VersionInvitationRequestController::deny()` calls `VersionInvitationRequestService::deny()`, setting `status = denied`, `decided_at`, `decided_by_user_id`. No `version_invitations` row is created or touched. **No server-sent email is generated** — per the source design doc, the `version-invitation-requests.denied` landing page offers a `mailto:` link (prefilled to the teacher's email, with a subject/body starter matching the source doc's wording) that the Event Manager can open on their own device to compose a custom reason. The reason is **not** captured or persisted server-side; `version_invitation_requests` has no `denial_reason` column by design.

**Resolved: signed-route expiry window is 7 days** (`now()->addDays(7)` in `RequestInvitation::notifyEventManagers()`), matching the existing precedent set by `StudentClaimMail`'s approve/deny links (`app/Livewire/Students/Index.php`) rather than the `school-email.verify` links' 3/30-day range — this was genuinely undecided at design time and picked at implementation for consistency with the closest existing analog, not re-confirmed with the product owner.

**Authorization.** The request action itself is teacher-self-service (`RequestInvitation::mount()` uses the authenticated teacher; redirects to Registration if already invited, `403`s if outside the eligible pool). The Approve/Deny signed routes bypass normal auth (by design — see above) but `VersionInvitationRequestController::authorizeDecidingUser()` still validates that the encoded `{user}` actually holds the Event Manager role for that Version's Event (`VersionRoleAssignmentService::canManageEvent`) before acting, in case a role was revoked between email-send and click — a tampered signature is rejected by the `signed` middleware itself (403) before the controller runs.

**Not built this phase (flag, don't scaffold):** any in-app "pending requests" admin screen — this phase is email-driven only, no dashboard of open requests; a re-request cooldown or rate limit (a teacher could theoretically request, get denied, and immediately re-request — no guard against this yet, left to the Event Manager's own judgment); the `registrations/{version}` (Registration) page itself doesn't yet filter its version listing by invitation/eligibility status (§6.2's access rules describe the intended nav behavior, but `Registrations/Index.php` and the top-level Sidebar in `app.blade.php` haven't been rewired to consult `VersionInvitationEligibilityService`/`version_invitations` yet — flagged as a follow-up, not done as part of this pass to avoid touching already-tested, unrelated code without sign-off).

**Test coverage.** `tests/Feature/Services/VersionInvitationRequestServiceTest.php` (12 tests: `canRequest`/`request`/`approve`/`deny`, including the denied-row-resets-to-pending and already-decided-throws cases), `tests/Feature/Livewire/Registrations/RequestInvitationTest.php` (6 tests: 403 outside the pool, redirect when already invited, request creates a row and emails each Event Manager, disabled "Request Sent" state, re-request after denial), and `tests/Feature/VersionInvitationRequestControllerTest.php` (6 tests: approve creates the invitation and emails the teacher, second Event Manager sees "Already handled" with no double-invite/double-email, deny leaves no invitation and offers the `mailto:` link, 403 for a non-Event-Manager `{user}`, tampered signature rejected).

---

### 5.9 Audition Environment (Room, Judge assignment, Scoring rubric — Version configuration)

Designed and built 2026-07-29. Scoped from a source design doc (`Audition_environment.docx`) plus a clarifying-question pass with the product owner. Configures the rooms, judge assignments, and scoring rubric a Version uses during adjudication — the setup half of the not-yet-built Adjudication Wizard (§7.2). Only Configuration-phase CRUD and the `scores` table schema are in scope this pass; live judge scoring UI, Tab Room, and cutoff/ensemble-assignment logic remain §7 reference-only.

**Room applies identically regardless of `audition_type`.** Resolved 2026-07-29 (an open question during design): the only difference between an in-person and a remote audition is whether the Candidate is physically present — a "blind" in-person audition (judges facing away) is functionally indistinguishable from a remote one to the judge. Room, Score Category/Factor assignment, and Judge assignment are therefore a single shared model for both modes; `audition_type` (§5.1) does not branch this domain at all.

**`version_scorings` dropped.** The source doc described `version_scorings` (flat `file_type`/`segment` columns) as "an alternative to using score_categories and score_factors." Resolved: not built. It offered no technical or performance benefit at this data volume — a rubric is a few dozen admin-edited rows, not a high-read table — and would have duplicated the category label across every factor row with no FK integrity, trading away the Event/Version override model for a join-avoidance that doesn't matter here. Denormalization is reserved for the genuinely high-cardinality, high-read `scores` table below, matching the existing `audition_results`/`candidate_status_history` precedent.

**`score_categories` table** — new, this phase:

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigIncrements | no | — | PK |
| `event_id` | foreignId | no | — | FK → events; cascade on delete |
| `version_id` | foreignId | yes | null | FK → versions; cascade on delete; nullable — see resolution rule below |
| `description` | string | no | — | |
| `order_by` | unsignedTinyInt | no | 1 | Display/report order |
| `created_at` / `updated_at` | timestamp | yes | null | Laravel timestamps |

**`score_factors` table** — new, this phase:

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigIncrements | no | — | PK |
| `event_id` | foreignId | no | — | FK → events; cascade on delete |
| `version_id` | foreignId | yes | null | FK → versions; cascade on delete; nullable — see resolution rule below |
| `score_category_id` | foreignId | no | — | FK → score_categories; cascade on delete |
| `description` | string | no | — | |
| `abbreviation` | string | no | — | Used in tight-spacing tables/reports |
| `best` | unsignedTinyInt | no | — | Best possible score for this factor |
| `worst` | unsignedTinyInt | no | — | Worst possible score for this factor |
| `interval_by` | unsignedTinyInt | no | 1 | Non-consecutive scoring step (e.g. 3 → 3,6,9,12) |
| `multiplier` | unsignedTinyInt | no | 1 | |
| `tolerance` | unsignedTinyInt | yes | null | Future-proofing only — tolerance is currently enforced at the Room level (§5.2 precedent), not per-factor |
| `order_by` | unsignedTinyInt | no | 1 | Display/report order |
| `created_at` / `updated_at` | timestamp | yes | null | Laravel timestamps |

**Event/Version resolution rule.** A Version with zero `score_categories` rows of its own inherits the Event's full rubric (`version_id IS NULL` rows); a Version with one or more of its own rows uses only its own set — all-or-nothing, not a per-row merge. Same "zero rows means unrestricted/fallback" convention already used for `version_counties` (§5.4) and `event_grades` (§6.2). Implement as `Version::availableScoreCategories()` / `availableScoreFactors()`. **Not built this phase (flag):** the authoring UI for creating a Version-specific override; the data model supports it, but only the Event-level rubric screen ships this pass.

**`version_rooms` table** — new, this phase:

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigIncrements | no | — | PK |
| `version_id` | foreignId | no | — | FK → versions; cascade on delete |
| `name` | string | no | — | e.g. "Soprano I" |
| `tolerance` | unsignedTinyInt | yes | null | Acceptable point spread between judge totals; null = not applied, zero = scores must be identical |
| `order_by` | unsignedTinyInt | no | 1 | Display order |
| `created_at` / `updated_at` | timestamp | yes | null | Laravel timestamps |

**`room_score_categories` pivot** — `room_id` (FK → version_rooms, cascade), `score_category_id` (FK → score_categories, cascade). A Room's scoring rubric is the union of its assigned categories' factors — no per-factor cherry-picking.

**`room_voice_parts` pivot** — `room_id` (FK → version_rooms, cascade), `voice_part_id` (FK → voice_parts, cascade). `VersionRoom::voiceParts()` ordered by `voice_parts.sort_order`, same rule as `Ensemble::voiceParts()` (§4.4).

**`room_judges` table** — new, this phase. Renamed from the source doc's "Judge" — this row is an assignment (person + role + room + version), not a person entity; the person is the existing `User` model:

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigIncrements | no | — | PK |
| `version_id` | foreignId | no | — | FK → versions; cascade on delete; denormalized alongside `room_id` for direct query without a join, matching the `audition_results` precedent (§5.2) |
| `room_id` | foreignId | no | — | FK → version_rooms; cascade on delete |
| `user_id` | foreignId | no | — | FK → users; cascade on delete — any User, not scoped to Teacher, since some judges are retired teachers or external judges who may never hold a Teacher role |
| `judge_type` | enum | no | — | `JudgeType` (§8.13) |
| `status` | enum | no | assigned | `JudgeStatus` (§8.14); default `Assigned` at creation — day-of transitions belong to the not-yet-built Adjudication phase |
| `created_at` / `updated_at` | timestamp | yes | null | Laravel timestamps |

**Judge type is a single flexible set, not gated by `audition_type`.** Resolved 2026-07-29 (an open question during design): since Room applies identically to in-person and remote auditions, there's no structural reason to enforce two separate judge-type sets by modality. The source doc's two label sets (in-person: head judge/judge2/judge3/judge4/judge monitor/monitor; remote: lead judge/judge1/judge2/judge3) more likely reflect different sponsoring organizations' own vocabulary (§7.8 already flags NJMEA/MACDA/CJMEA as having different rules) than a real in-person/remote rule. `JudgeType` merges both vocabularies into one enum with no runtime validation against `audition_type`; if organization-specific label needs emerge later, this can move to Event-configurable data (like `score_categories`) instead of a hardcoded enum.

**`scores` table** — new, this phase, schema/model only (no entry UI — live scoring remains §7.2 reference-only). Denormalized to avoid highly-joined queries at report time, matching the existing `audition_results`/`candidate_status_history` denormalization precedent:

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigIncrements | no | — | PK |
| `version_id` | foreignId | no | — | FK → versions |
| `candidate_id` | foreignId | no | — | FK → candidates |
| `student_id` | foreignId | no | — | FK → students; frozen at scoring time — same freeze-at-a-point-in-time pattern as `obligation_snapshot` (§5.6), not a live-state duplicate |
| `school_id` | foreignId | no | — | FK → schools; frozen at scoring time |
| `score_category_id` | foreignId | no | — | FK → score_categories |
| `score_category_order_by` | unsignedTinyInt | no | — | Snapshot of `order_by` at scoring time, so later rubric reordering doesn't reshuffle historical reports |
| `score_factor_id` | foreignId | no | — | FK → score_factors |
| `score_factor_order_by` | unsignedTinyInt | no | — | Snapshot, same rationale |
| `judge_id` | foreignId | no | — | FK → room_judges |
| `judge_order_by` | unsignedTinyInt | no | — | Snapshot, same rationale |
| `voice_part_id` | foreignId | no | — | FK → voice_parts; frozen at scoring time — `candidates.voice_part_id` remains the live source of truth (§5.2 invariant); this is the historical record of what part the Candidate was actually judged in |
| `voice_part_order_by` | unsignedSmallInt | no | — | Snapshot, same rationale |
| `score` | unsignedTinyInt | no | — | The recorded score value |
| `created_at` / `updated_at` | timestamp | yes | null | Laravel timestamps |

**Implementation.** `VersionRooms` Livewire component (`/events/versions/{version}/rooms`, route `events.versions.rooms`), reachable from a new "Rooms" button on the Event show page alongside Invitations/Pitch Files. Add/edit is a single modal: room name/tolerance, score-category checkboxes (scoped to `availableScoreCategories()`), voice-part checkboxes (scoped to `availableVoiceParts()`), and an inline judges editor (add/remove rows of email + `JudgeType`, resolved to an existing `User` by email at save time — any User, not scoped to Teacher, per §5.9's "retired teachers/external judges" note). Saving a room fully syncs its `room_score_categories`/`room_voice_parts`/`room_judges` rows to whatever was submitted (removed rows are deleted, not left orphaned) rather than only ever adding. Drag-and-drop reorder is native HTML5, same as `VersionPitchFiles` (`wire:sort` is broken on Flux table rows in this codebase). Gated identically to `VersionEdit`/`VersionPitchFiles`/`VersionInvitations`: `VersionRoleAssignmentService::canManageEvent`. Test coverage: `ScoreCategoryResolutionTest.php` (7 tests: Event/Version rubric inheritance and the all-or-nothing override), `VersionRoomsTest.php` (16 tests: authorization, CRUD, category/voice-part sync, judge-row create/update-in-place/remove, reorder), `ScoreTest.php` (2 tests: every `Score` relation resolves, cascade-delete from `Candidate`), plus 2 new `EnumsTest.php` cases. 27 new tests; 651/651 passing app-wide, PHPStan clean.

**Audition-environment access control (designed and built 2026-07-29).** Resolved: access to every audition-environment configuration screen (Rooms, and the Scoring Rubric screen below) is Event Manager or Registration Manager/Co-Registration Manager — not the broader `canManageEvent` gate `VersionRooms` shipped with initially (that gate is Founder + Event Manager only). New `VersionRoleAssignmentService::canManageAuditionEnvironment(User $user, Version $version): bool`: Founder or Event-wide Event Manager (existing `canManageEvent`), **or** Registration Manager/Co-Registration Manager held specifically on that Version — per-Version, not Event-wide, matching how every role except Event Manager already works in this service (Event Manager is the one deliberate exception, since it's the role that bootstraps a brand-new Version before any roles exist on it — see the service's own class-level docblock). `VersionRooms` is retrofitted to this check in place of `canManageEvent`.

**`VersionScoringRubric` screen (designed and built 2026-07-29).** New Livewire component, route `events.versions.scoring-rubric` (`/events/versions/{version}/scoring-rubric`), gated by `canManageAuditionEnvironment`. Two-level nested CRUD (categories containing factors) over `score_categories`/`score_factors`, which were schema-only with no authoring UI until now. Inherit-vs-customize mode driven by `$version->scoreCategories()->exists()`:

- **Inheriting** (no Version-owned rows): edits the Event's default rows (`version_id IS NULL`) directly — every Version inheriting the default sees the change. A "Customize for this Version" action forks: duplicates every Event-default category and its factors into new Version-owned rows (same field values), after which the screen edits those instead.
- **Customized** (Version has its own rows): edits only the Version-owned rows. A "Revert to Event default" action (`wire:confirm`-gated) deletes the Version-owned category rows; factor rows cascade-delete via the existing FK.

Ordering is numeric `order_by` inputs + "Save Order" per level (categories, and factors within each category) — not full drag-and-drop; two nested DnD levels was judged more complexity than this pass needs, flagged in case that's wrong. Nav: a "Scoring Rubric" button (fuchsia) added next to Rooms (teal) on the Event show page, both gated by the new per-Version `versionAuditionAccess` map computed in `Events\Show::render()` — distinct from the `canManageEvent`-gated Invitations/Pitch Files buttons alongside them.

**Test coverage.** `VersionRoleAssignmentServiceTest.php` gained 6 tests for `canManageAuditionEnvironment` (Registration Manager and Co-Registration Manager pass per-Version, sibling-Version and unrelated-role cases fail, Event Manager sibling-version fallback and Founder still pass). `VersionRoomsTest.php` gained 3 tests confirming the retrofit (Registration Manager and Co-Registration Manager now pass, an unrelated role like Tab Room Manager still 403s). `VersionScoringRubricTest.php` (new file, 21 tests): authorization for all five role cases, category/factor CRUD including the cross-Version `activeCategory()`/`activeFactor()` ownership guard (a category from a different Event 404s via `ModelNotFoundException`, asserted with `toThrow` rather than `assertStatus` — a mid-action `findOrFail` doesn't convert to an HTTP response inside `Livewire::test()->call()` the way a mount-time 403/404 does), fork-on-customize (verifies the Event default is untouched and the Version-owned copy edits independently afterward), revert-to-default (cascade delete, mode flips back), and the 409 guards on calling customize/revert out of turn. 30 new tests; 681/681 passing app-wide, PHPStan clean.

**Not built this phase (flag, don't scaffold):** live judge-facing scoring entry UI, Tab Room, `AdjudicationStatus`, `CutoffStrategy` — unchanged from §7; `JudgeStatus` day-of transition UI beyond the `Assigned` default.

---

## 6. Lifecycle — In Scope

The Version moves through the following workflow. Phases 6.1–6.2 are the build target for this phase; later phases are in §7 (reference only).

### 6.1 Configuration

The Event Manager sets up the Version's rules and configurations (adjudication rules, milestone dates, fees, audition type, membership requirements, application type, timeslots, county data, ensemble order, default invitations, pitch-file visibility, and role assignments — all per §5), and builds the Version's invitation list, controlling which eligible teachers may enroll Candidates (§5.4).

### 6.2 Registration

Teachers register their eligible Candidates for an audition. Teachers control:

- Their Candidates' access to self-registration material on StudentFolder.info.
- Their Candidates' ability to submit e-payments for fees.
- Creation/upload of registration materials — or, for self-registering candidates, approval of those materials.

Only Candidates who have met all registration requirements and been approved by the teacher move into adjudication.

**Access & navigation (designed 2026-07-16; wired 2026-07-16; revised to three buckets 2026-07-16).** The Sidebar's top-level "Registrations" nav item (`resources/views/components/layouts/app.blade.php`) is visible to a teacher only when `VersionInvitationEligibilityService::hasAnyRegistrationAccess()` is true — an existing `version_invitations` row, an existing active candidate, or at least one currently-open (`version_dates` type `teacher`, §5.2) Version they're eligible for per §5.4's OR logic (county match or org membership, unrestricted if the Version has no `version_counties` rows). Once inside, `App\Livewire\Registrations\Index` (`Registrations/Index.php::buildSections()`) sorts every candidate Version into **at most one of three mutually exclusive buckets** (a Version satisfying none is omitted entirely rather than shown with a dead-end link):

- **"Open for Registration"** — the window is open AND the teacher has an existing `version_invitations` row (any status, including `Rejected` — the obligations-rejected callout inside `VersionDashboard` stays reachable). Computed eligibility is **not** re-checked here: an invited teacher whose eligibility has since lapsed (e.g. a `version_counties` reconfiguration) still sees "Manage," since the invitation itself — not the pool computation — is what grants standing. This intentionally reversed an earlier version of this rule that *did* re-check eligibility for invited teachers and dropped them from the list; that turned out to be the wrong call and was corrected the same day.
- **"Invitation Available"** — the window is open, the teacher has **no** invitation row, but is eligible per §5.4's `isEligible()`. This is the self-service discovery surface for §5.8's Request Invitation flow, deliberately kept as its own visually-distinct section (separate heading, amber "Not Invited" badge) rather than folded into "Open for Registration" — an early version showed these rows inside "Open for Registration" itself, which read as if the teacher could already act on them; product feedback (screenshot-driven) called that out explicitly.
- **"Active Candidates"** — the window is not open AND the teacher has at least one Candidate there still in a registration state (`eligible`/`pending`/`registered`, §8.3). Eligibility and invitation status are never considered once the window has closed — an existing Candidate is itself proof of prior access.

**Not wired**: the dashboard's "Events" card (`resources/views/dashboard.blade.php`) still just lists active Events with no per-Event/Version registration affordance — the source design doc mentioned "the Events card" alongside the Sidebar, but that card has no per-item action links today for anything to hook into; flagged as a smaller follow-up, not done in this pass.

**Candidate access scope.** A teacher (or any other active+verified teacher at the same school, per the `TeacherRole::Coteacher` distinction on `school_teacher` — access follows the school relationship, not a per-candidate assignment) can view and manage every Candidate whose `school_id` matches one of their own active+verified `school_teacher` schools, for Versions where that school is participating — not only Candidates where they are the specific `teacher_id` of record. `candidates.teacher_id` still identifies the primary teacher for a Candidate (e.g., for default correspondence), but it is not the access gate.

**Audition uploads (`audition_type = remote`).** Each Candidate must have an uploaded recording for every `version_upload_files` slot (§5.2) before the "Upload of required audio/video files" milestone below is satisfied; the teacher (or co-teacher, per the access rule above) reviews and approves or rejects each one. Rejecting deletes the file (and its S3 object) outright and re-opens that slot for another upload — see `candidate_upload_files` in §5.2 for the schema and the delete-on-reject rationale.

**Automatic enrollment (built 2026-07-16) — the manual "Enroll a Student" form is gone.** Candidates are no longer manually enrolled by a teacher picking a student from a dropdown on `VersionDashboard` — that form and its backing `enroll()` action were removed entirely. Instead, `App\Services\AutoEnrollmentService` proactively enrolls every eligible student the moment they become eligible, via two model-Observer triggers:

- **`App\Observers\VersionInvitationObserver::created()`** — fires when a teacher is newly invited (either path: an Event Manager's direct invite, §5.4, or an approved self-service request, §5.8). Enrolls every one of that teacher's currently-eligible students into the Version. Scoped to Versions currently `Active` — an invitation issued while the Version is still `Sandbox` does not populate real Candidate data before an Event Manager has actually opened it (an inferred consistency choice, not explicitly requested, since the source instruction didn't specify this trigger needed the Active gate — flagged in case it's wrong).
- **`App\Observers\StudentTeacherObserver`** (`created`/`updated`) — fires when a student is newly added to a teacher's roster, or an existing `student_teacher` row is reactivated (`is_active` flips to `true`). Enrolls that one student into every Version that is currently `Active` **and** for which the teacher already holds an invitation (any status). **Known gap**: this does not cover the indirect `is_active` cascade in `SchoolStudentObserver::saved()` (a student's school reactivating cascades to `student_teacher.is_active` via a raw query-builder `update()`, which never fires Eloquent model events) — direct roster-add/reactivation call sites are covered, that indirect path is not.

**"Eligible" now includes grade matching, everywhere `EligibilityService::eligibleStudents()` is used** (previously grade was explicitly *not* checked there, per the source comment "grade filtering is intentionally skipped here"). A student's grade (`Student::grade`, computed from `school_student.class_of` and the school's `senior_year`) must be one of the Event's `event_grades` rows — unrestricted if the Event has none configured (same "zero rows means unrestricted" convention as `version_counties`, §5.4). Grade is a computed attribute, not a stored column, so it's filtered in PHP after the query rather than in SQL.

**Voice part on auto-enrolled Candidates.** `candidates.voice_part_id` is NOT NULL and there is still no UI to change it after creation (`CandidateDetail` doesn't touch it). Resolution order, computed by `AutoEnrollmentService`: the student's own `voice_part_id` if it's one of the Version's `availableVoiceParts()` (the Event's ensemble-linked voice parts); otherwise the first available voice part (ordered by `sort_order`); if the Event has no ensemble voice parts configured at all yet, the enrollment is skipped entirely rather than guessing.

**UX requirement (poka-yoke).** This phase benefits greatly from a poka-yoke approach: the teacher must always be able to see what is done, what is missing, and the upcoming milestone dates. Checklists and strong visual status cues are critical. The interface must make it trivial to answer:

> *"Why isn't this student in 'registered' status?"*

**Candidate gate — `eligible` → `pending` → `registered`.** Depending on Event/Version configuration, all applicable milestone items below must be satisfied to promote a Candidate from `eligible` to `registered`:

- Emergency contact (name / email / phone)
- Candidate home address
- Candidate cell phone
- Candidate email address
- eApplication approval
- Teacher application approval
- Upload of required audio/video files
- Teacher approval of required audio/video files

If a candidate has one or more milestone items completed but not the full set required by the Event/Version configuration, the Candidate status is `pending`.

Which items are required is driven by the data-collection requirement flags on the Event (§3.2). The gate logic is the responsibility of the `EligibilityService`; enrollment and promotion run through `CandidateService::enroll()`, and every status change is recorded in `candidate_status_history`.

**Teacher close-out.** Teachers close their individual registration phase by printing an estimate/invoice that includes pertinent candidate information, total paid, amount due, and required membership materials.

### 6.3 Email notifications

- Teachers receive a weekly registration-progress update (opt-out-able).
- Event Manager(s) and (Co-)Registration Manager(s) receive a weekly Version registration-progress update (opt-out-able).

---

## 7. Lifecycle — Reference Only (Not This Phase)

The following phases are documented so the data model stays forward-compatible. **Do not scaffold UI or algorithms for these now.**

### 7.1 Pre-adjudication

Registration Manager(s) review the physical materials submitted by teachers against TheDirectorsRoom.com records: signature completeness (candidate, parent, teacher, principal), payment reconciliation (with check payments recorded). Managers send a form email confirming receipt and a custom email listing items to reconcile.

### 7.2 Adjudication

Judges navigate to a Judge page listing the Candidate ids to adjudicate and the adjudication form. A judge sees other judges' scores only after entering their own. Scores outside room tolerance are immediately highlighted but not prohibited. Individual and room status (pending, in-progress, completed, out-of-tolerance, error) is always visible.

### 7.3 Tab Room

The Tab Room Manager sees overall adjudication progress in graphic form per Version, per Room, per Voice Part, and per-Candidate detail, and can override judge scores. The Event Manager closes the Version and releases results via the Tab Room.

### 7.4 Score cut-offs

On adjudication completion, the Event Manager builds Ensemble membership by setting cut-off scores per voice part. Candidates at or better than the cut-off are selected; those below are not. Event/Version configuration determines whether scores sort ascending or descending (i.e., whether lower or higher is better).

### 7.5 Ensemble assignment — align to the CutoffStrategy enum

The four assignment behaviors map to the existing `CutoffStrategy` enum and interface. The agent should implement against those enum cases rather than introducing new names. Mapping:

| Original spec name | CutoffStrategy case | Behavior |
|---|---|---|
| Single ensemble | `PerVoicePartPerEnsemble` | Same-or-better than the cutoff → assigned; otherwise unassigned. The single-ensemble base case. |
| Multiple ensembles, cascading cutoffs | `SequentialEnsembleFill` | Ensembles have a priority order; multiple cutoffs per voice part. Fill ensemble 1 to its cutoff, then ensemble 2, etc., until `ensemble_count` is met. |
| Multiple ensembles, alternating cutoff | `AlternatingEnsembleAssignment` | One shared candidate pool; score routes a candidate between ensembles (tiers of the same population). Priority order, one cutoff per voice part; starting from the best score, assign candidates to ensembles in alternating order per score until each cutoff is met. |
| Multiple ensembles defined by grade | `GradeSegmentedEnsembles` | Candidates are partitioned by grade before any score comparison (e.g., a 7th-grade soprano competes only for the junior ensemble, an 11th-grade soprano only for the senior ensemble; they never compete). Ensembles may use different voicing taxonomies; each applies its own per-voice-part cutoff. Disjoint, independent ensembles selected in parallel — not tiers of one pool. |

### 7.6 Results

When the Event Manager closes the Version via the Tab Room:

- A Version-wide confidential results summary is created (confidential).
- A Version-wide detailed results summary is created (detailed; Event Manager only).
- Teachers see their Candidates' results on the Event results page, including:
  - A printable summary PDF of all the teacher's registered candidates,
  - A printable per-candidate PDF for individual discussions, and
  - If configured: a printable confidential PDF, and retained access to pitch files.

### 7.7 Rehearsal

On Version close, the Rehearsal Manager gains access to detailed results and contact information for each accepted member of each Ensemble, as a web page and CSV file(s).

### 7.8 Alternates

Identification of alternate candidates to replace accepted ensemble members who decline or no-show post-acceptance. Needs rules from NJMEA; need to be tested with MACDA and CJMEA.

---

## 8. Enum & Status Reference

Consolidated enums. Define each as a PHP backed enum. Transitions marked "TBD" need confirmation.

### 8.1 EventStatus

`sandbox | active | inactive | closed` — default `sandbox`. Transitions per §3.1.

### 8.2 Frequency

`annual | biannual | biennial | monthly` — default `annual`.

### 8.3 Candidate status

Twelve states. Every change is written to `candidate_status_history`. Allowed transitions:

- `eligible → pending → registered` (registration milestones; see §6.2)
- `registered → withdrew | teacher_withdrawn` (post-registered, pre-adjudication)
- `registered → adjudicated | no_show | incomplete` (adjudication results)
- `adjudicated → accepted | not_accepted` (cutoff assignment; §7.4–§7.5)
- `accepted → declined | removed` (post-acceptance; candidate or Rehearsal Manager)

Full set (12): `eligible`, `pending`, `registered`, `withdrew`, `teacher_withdrawn`, `adjudicated`, `no_show`, `incomplete`, `accepted`, `not_accepted`, `declined`, `removed`.

### 8.4 Adjudication status (reference)

`pending | in_progress | completed | out_of_tolerance | error` — applies to both individual Candidate and room.

### 8.5 CutoffStrategy (reference)

`PerVoicePartPerEnsemble | SequentialEnsembleFill | AlternatingEnsembleAssignment | GradeSegmentedEnsembles` — see §7.5.

### 8.6 AuditionType

`in_person | remote`.

### 8.7 ApplicationType

`eapplication | pdf`.

### 8.8 VersionInvitationStatus

`invited | obligated | participating` — no `eligible` case; absence of a `version_invitations` row is the eligible state. See §5.4. Default on row creation: `invited`. `participating` is still reserved for the not-yet-built registration workflow. `obligated` is no longer reserved-only — see §5.6: it is now written by `VersionObligationResponseObserver` when a teacher accepts Version Obligations.

### 8.9 VersionObligationStatus

`draft | published` — default `draft`. See §5.6.

### 8.10 ObligationDecision

`accepted | rejected`. See §5.6.

### 8.11 VersionInvitationRequestStatus

`pending | denied | approved` — default `pending`. See §5.8. Distinct from `VersionInvitationStatus` (§8.8) — a request row is deleted-in-effect (reset to `pending`) on re-request rather than accumulating, and `approved` triggers creation of the actual `VersionInvitation` row rather than being read as the invitation state itself.

### 8.12 CandidateUploadStatus

`pending | approved` — default `pending`. See §5.2 (`candidate_upload_files`). No `rejected` case by design — a rejection deletes the row instead of transitioning to a third state.

### 8.13 JudgeType

`HeadJudge | LeadJudge | Judge1 | Judge2 | Judge3 | Judge4 | JudgeMonitor | Monitor`. See §5.9. A single flexible set merging both label conventions from the source doc — not validated against `audition_type`.

### 8.14 JudgeStatus

`Assigned | Exempt | Completed | Delegated | LeftEarly | NoShow | Substitute` — default `Assigned`. See §5.9 (`room_judges`). Day-of transitions beyond the default belong to the not-yet-built Adjudication phase.

---

## 9. Open Decisions (consolidated)

Confirmed as of the 2026-07-09 implementation audit:

1. ~~**Uncommitted change.**~~ Resolved — the `tests/Feature/EnumsTest.php` `VersionDateType` case-order fix was committed in `6e1ab02 "Updt: Checks and missing configuration items."`
2. ~~**`voice_parts` naming drift.**~~ Resolved — spec §4.3 now matches the shipped `name` / `abbr` / `sort_order` columns.
3. ~~**`emergency_contact` naming drift.**~~ Resolved — spec §5.2 now matches the shipped `emergency_contacts` table (`cell_phone`/`home_phone`/`work_phone`/`relationship`, `EmergencyContactRelationship` enum, `BestPhone` enum).
4. ~~**`EnsembleVoicePart` pivot ordering.**~~ Resolved — confirmed by design: the pivot intentionally carries no ordering column, and `Ensemble::voiceParts()` must always order by `voice_parts.sort_order`. Documented in §4.4.
5. ~~**`version_timeslots` has no seeder or UI yet.**~~ Resolved — no seeder is planned; past timeslots are irrelevant, so this row is dropped from the open list.
6. **`epayment_credentials` is schema-only.** No seeder or UI, as the spec anticipates ("next-phase implementation"). No action needed this phase.
7. **Reference-only items remain unbuilt by design**, confirming the §0.2 scope fence held during implementation: `version_adjudication` (rooms, scoring categories/factors, judge types), `audition_results`, `AdjudicationStatus` enum, `CutoffStrategy` enum. Do not scaffold until the Adjudication Wizard phase begins.
8. ~~**Version Invitations (§5.4) — new capability, not yet built.**~~ Resolved — built and committed in `9e015fa "Add: Invitations functionality."`: `VersionInvitationStatus` enum, `VersionInvitation` model + migration, `VersionInvitationEligibilityService`, `VersionInvitationRosterRow`, the `VersionInvitations` Livewire component + view, and feature/unit test coverage. Membership eligibility leg accepts any `Membership` row regardless of expiration. County leg is unrestricted when a Version has no `version_counties` rows. Multi-school teachers get one roster row. Modeled as a new `version_invitations` table, intentionally separate from `EventInvitationRequest`.
9. ~~**Still intentionally deferred within §5.4: any UI for the `obligated`/`participating` transitions.**~~ Partially resolved — the `obligated` transition now exists (§5.6: `VersionObligationResponseObserver` writes it on teacher acceptance). Still deferred: search/filter/pagination on the invitation roster, email notification on invite, and any UI for the `participating` transition — the latter still belongs to the not-yet-designed registration workflow.
10. ~~**Version Pitch Files (§5.5) — new capability, not yet built.**~~ Resolved — built and committed in `b70abb3 "Add: Initial pitch files functionality."` and `2a55580 "Updt: Completed pitch files functionality."`: `VersionPitchFile` model + migration (`version_pitch_files`), the `VersionPitchFiles` Livewire component + view (search/filter/sort/drag-reorder), S3-backed file storage with delete-on-replace and delete-on-remove, and feature test coverage (`VersionPitchFilesTest`, 14 tests). `voice_part_id` is validated against `Version::availableVoiceParts()`. Gated by the same `canManageEvent` check as `VersionEdit`/`VersionInvitations`. `VoicePartSeeder` gained an `'ALL'` voice part (for files that apply across parts) as part of this work; `SeedersTest` was updated from 17 to 18 expected voice parts to match.
11. **Still intentionally deferred within §5.5** (flagged, not scaffolded): enforcement of `pitch_file_visibility` (teacher/candidate/both) and the post-close visibility windows, and any candidate/teacher-facing display of pitch files on StudentFolder.info — this phase is Event Manager CRUD only.
12. ~~**Version Obligations (§5.6) — new capability, not yet built.**~~ Resolved — built 2026-07-09 (not yet committed): `VersionObligationStatus` and `ObligationDecision` enums, `VersionObligation` + `VersionObligationResponse` models/migrations, `VersionObligationObserver` (HTMLPurifier sanitization via a new `obligations` profile in `config/purifier.php`, itself a new `mews/purifier` dependency), `VersionObligationResponseObserver` (writes `VersionInvitationStatus::Obligated`), the "Obligations" tab added to `VersionEdit` (admin CRUD, save/publish/unpublish, Preview modal), and `App\Livewire\Registrations\VersionObligations` (teacher-facing accept/reject page).
13. ~~**Still intentionally deferred within §5.6: a dedicated Pest feature test file.**~~ Resolved — added `tests/Feature/Livewire/Events/VersionEditTest.php` (+9 tests: save/publish/unpublish, sanitization, Preview modal), `tests/Feature/Livewire/Registrations/VersionObligationsTest.php` (new file, 8 tests: 404-when-uninvited, draft invisibility, merge-field resolution, accept/reject, toggle behavior, frozen snapshot immunity to later edits), and `tests/Feature/VersionObligationObserversTest.php` (new file, 5 tests, matching the `SchoolStudentObserverTest` direct-model-observer pattern — including a regression test locking in a `getRawOriginal()`-lags-by-one-save timing bug caught and fixed during this work). 522/522 app-wide, up from the 500 baseline. Still intentionally deferred within §5.6: PDF rendition of the obligations document, an "insert merge field" toolbar affordance, and any consequence for a `rejected` decision beyond logging it (open question: should rejection gate further participation, or stay purely informational?).
14. **Candidate Registration workflow (§5.8, `candidate_upload_files` in §5.2, access/navigation rules in §6.2) — designed 2026-07-16.** Scoped from a source design doc (`Registration_design.docx`) plus a clarifying-question pass with the product owner, resolving: (1) teacher-initiated invitation requests get their own Version-scoped `version_invitation_requests` table rather than reusing the Event-scoped `EventInvitationRequest` or overloading `VersionInvitationStatus`; (2) Event Manager approve/deny happens via personalized signed one-click email links, not an in-app queue; (3) Sidebar/Registration nav visibility reuses `VersionInvitationEligibilityService`'s existing OR logic rather than the source doc's stricter AND reading; (4) actual audition-recording upload/approve/reject gets a real schema (`candidate_upload_files`) now, not deferred as reference-only; (5) "co-teacher" candidate access is school-based (any active+verified teacher at the Candidate's school), not a per-candidate assignment. **§5.8 built 2026-07-16**: `version_invitation_requests` migration/model/enum, `VersionInvitationEligibilityService::isEligible()`, `VersionInvitationRequestService` (canRequest/request/approve/deny), `VersionRoleAssignmentService::eventManagersForEvent()`, the `RequestInvitation` teacher-facing Livewire page, `VersionInvitationRequestController`'s signed approve/deny routes (with a real "already handled" landing page, not a 404), and the submitted/approved mail classes — 24 new tests, PHPStan clean. **Nav filtering wired 2026-07-16**: `VersionInvitationEligibilityService::hasAnyRegistrationAccess()` gates the Sidebar's "Registrations" item, `Registrations/Index.php` filters its "Open for Registration" list by the same eligibility rules and routes each entry to Request Invitation or Manage accordingly — 10 more tests. 586/586 passing app-wide as of this pass, PHPStan clean. Signed-route expiry resolved at 7 days (matched to the closest existing precedent, `StudentClaimMail`). **Still open:** `candidate_upload_files` (§5.2) is designed but not built; the dashboard's "Events" card still has no per-item registration affordance (§6.2); no in-app "pending requests" screen (email-only, by design); no re-request cooldown/rate-limit.

**Authorization gap found and fixed 2026-07-16.** `Registrations/VersionDashboard::mount()` had no invitation check at all — until this fix, any teacher with an active school could open `/registrations/{version}` directly (nav visibility is not an authorization boundary) and enroll candidates into a Version they were never invited to, since `EligibilityService::eligibleStudents()` only ever checked `isBlockedByRejectedObligations()` (a teacher who'd never been invited at all sailed through, since there was no row to be "rejected"). Fixed at two layers, mirroring the pattern already established by `Registrations/VersionObligations::mount()`'s single-gate convention:
- **Page-level (primary boundary):** `VersionDashboard::mount()` now checks for an existing `version_invitations` row (any status — including `Rejected`, so the existing obligations-rejected callout stays reachable). No row + eligible → redirect to `registrations.request-invitation` (mirrors `RequestInvitation::mount()`'s reverse redirect for an already-invited teacher, forming a symmetric pair). No row + ineligible → `403`. Since Livewire snapshots are checksum-signed and only ever issued after `mount()` completes, this is a real enforcement boundary, not just a first-load convenience check — a forged snapshot for an unauthorized Version can't be produced.
- **Service-level (defense-in-depth):** `EligibilityService` gained `isNotInvited(Version, Teacher): bool`, and `eligibleStudents()` now returns empty for a teacher with no invitation row, not just a rejected one — so the underlying data-access method is safe to call from anywhere, not only safe because the one known caller happens to gate first.

Test coverage: `VersionDashboardTest` gained 3 tests (redirect-when-eligible-uninvited, 403-when-ineligible-uninvited, and a direct `EligibilityService` defense-in-depth check) and every pre-existing test in that file was updated to set up a `version_invitations` row first, since none of them had one before. `EligibilityServiceTest` gained 2 tests (`isNotInvited` true/false, `eligibleStudents` empty with no invitation row) and its 5 pre-existing `eligibleStudents` tests were updated the same way, so each still isolates the specific condition it names rather than accidentally passing because the new gate alone made the result empty. 21 new/updated tests in these two files; full suite green, PHPStan clean.
15. **Open bug, unresolved: bulleted/numbered lists render with no visible marker inside `flux:editor`'s live admin UI (and its Preview modal), for both pasted-then-cleaned-up content and freshly toolbar-typed content.** Ruled out as a cause: this codebase's stored HTML (confirmed via direct DB read still has intact `<ul><li>` structure), the `mews/purifier` sanitization allowlist (includes `ul,ol,li`), and the Blade/Tailwind rendering path (a direct `view(...)->render()` test on the real persisted content produces correct `<ul>` + `list-disc` output). The defect is therefore isolated to the client-side `flux:editor`/TipTap layer — not yet root-caused (would need live browser DevTools inspection, which this agent cannot perform) and not yet reported to Flux support. Does not block any server-side functionality (save/publish/sanitize/accept/reject/merge-fields all verified correct regardless of how the editor visually renders lists while typing) but is a real polish gap before this ships to an actual Event Manager, given the source Teacher Obligations content is list-heavy.
16. **Automatic candidate enrollment (§6.2) — built 2026-07-16.** The manual "Enroll a Student" form on `VersionDashboard` is gone, replaced by proactive enrollment via two new Observers (`VersionInvitationObserver`, `StudentTeacherObserver`) and `App\Services\AutoEnrollmentService` — see §6.2 for the full trigger/eligibility/voice-part-resolution writeup. `EligibilityService::eligibleStudents()` gained grade matching (`event_grades`, unrestricted if unconfigured), applied uniformly rather than scoped to only the new automatic path, per explicit direction. 23 new/updated tests across `AutoEnrollmentTest.php`, `EligibilityServiceTest.php`, and `VersionDashboardTest.php` (5 removed, 1 renamed to reflect the removed UI); 608/608 passing app-wide, PHPStan clean. ~~**Known gap, flagged not fixed:** the indirect `student_teacher.is_active` cascade in `SchoolStudentObserver::saved()` uses a raw query-builder `update()` and never fires Eloquent events, so it does not trigger auto-enrollment — only direct roster-add/reactivation call sites do.~~ **Resolved same day**: `SchoolStudentObserver::saved()` now fetches the matching `student_teacher` rows and calls `->update()` on each pivot model individually rather than a single bulk query-builder statement, so `StudentTeacherObserver::updated()` fires normally when a student's school reactivation cascades their teacher-link back to active. One new test (`AutoEnrollmentTest.php`, 10 total now) confirms the cascade path triggers enrollment; the two pre-existing `SchoolStudentObserverTest.php` tests (direct cascade behavior, multi-school deactivation) still pass unchanged.
17. **Audition Environment (§5.9) — designed and built 2026-07-29.** Scoped from a source design doc (`Audition_environment.docx`) plus a clarifying-question pass with the product owner, resolving: (1) Room, Score Category/Factor, and Judge assignment apply identically to in-person and remote auditions — `audition_type` does not branch this domain, since the only difference between the two modes is whether the Candidate is physically present; (2) `version_scorings` (a flat alternative to score_categories/score_factors) is dropped — no technical/performance benefit at this data volume, and it would have traded away FK integrity and the Event/Version override model for a join-avoidance that doesn't matter on a few-dozen-row rubric table; (3) `score_categories`/`score_factors` are scoped to `event_id` with a nullable `version_id` override, all-or-nothing per Version (zero Version-owned rows = inherit the Event's set), matching the existing `version_counties`/`event_grades` "zero rows means unrestricted" convention; (4) the source doc's "Judge" table is renamed `RoomJudge`/`room_judges` — it's an assignment (person + role + room + version), not a person entity, avoiding confusion with the existing Spatie version-scoped role system (§5.3), which this is intentionally not part of; (5) `JudgeType` merges the source doc's in-person and remote label sets into one enum with no `audition_type` enforcement, since the two sets likely reflect different sponsoring organizations' own vocabulary (§7.8) rather than a real modality rule; (6) `scores` is built as schema/model only this phase (denormalized, frozen-at-scoring-time columns, matching the `audition_results`/`obligation_snapshot` precedent) — no live scoring entry UI, which remains §7.2 reference-only along with Tab Room and cutoff/ensemble-assignment logic. **Built:** `JudgeType`/`JudgeStatus` enums; `score_categories`/`score_factors` migrations/models with `Version::availableScoreCategories()`/`availableScoreFactors()` inheritance resolution; `version_rooms` + `room_score_categories`/`room_voice_parts` pivots + `VersionRoom` model; `room_judges` + `RoomJudge` model; the `VersionRooms` Event Manager CRUD screen (rooms, category/voice-part assignment, inline judge-by-email assignment, native drag-and-drop reorder); the schema-only `Score` model. 27 new tests (`ScoreCategoryResolutionTest.php`, `VersionRoomsTest.php`, `ScoreTest.php`, 2 new `EnumsTest.php` cases); 651/651 passing app-wide, PHPStan clean. **Still not built:** an authoring UI for `score_categories`/`score_factors` themselves (Event-level default rubric or Version-level override — both are direct-DB/tinker only for now); live judge-facing scoring entry UI, Tab Room, `AdjudicationStatus`, `CutoffStrategy` (unchanged §7 reference-only); `JudgeStatus` day-of transition UI beyond the `Assigned` default.
18. **Audition-environment access control + Scoring Rubric UI (§5.9) — designed and built 2026-07-29.** Follow-up to item 17, addressing its two flagged gaps: (1) `VersionRooms` shipped gated by `canManageEvent` (Founder + Event Manager only); the product owner wants Registration Manager/Co-Registration Manager included too, per-Version rather than Event-wide — new `VersionRoleAssignmentService::canManageAuditionEnvironment()`, with `VersionRooms` retrofitted to it; (2) `score_categories`/`score_factors` had no authoring UI at all — new `VersionScoringRubric` screen with an inherit-vs-customize fork/revert model (edit the Event default directly while inheriting; "Customize for this Version" duplicates it into Version-owned rows; "Revert to Event default" deletes them). See the design writeup above (§5.9, following the `scores` table spec) for full detail. Ordering deliberately uses numeric inputs, not drag-and-drop, for this nested two-level case. 30 new tests (`VersionRoleAssignmentServiceTest.php` +6, `VersionRoomsTest.php` +3, `VersionScoringRubricTest.php` new file with 21); 681/681 passing app-wide, PHPStan clean. **Not yet built:** everything already flagged as such throughout §5.9/§7 remains unbuilt — this item closes out both of item 17's gaps and adds nothing new to the deferred list.
19. **Co-Registration Manager delegation (§5.3) — designed and built 2026-08-04.** The product owner wanted Registration Managers able to assign the Co-Registration Manager role without opening the full Configure module to them. Resolved via clarifying questions with the product owner: (1) grantees are Founder/Event Manager (always) or Registration Manager specifically on that Version — not Co-Registration Manager itself, so the role cannot self-propagate; (2) the narrow permission covers both assign and revoke, symmetric with other CRUD screens in this app; (3) Co-Registration Manager assignment is **fully migrated** out of the generic Configure → Roles tab into a new dedicated screen — the two paths never coexist, so a Co-Registration Manager can never exist without a county assignment; (4) each assignment is paired with a set of "county of responsibility" rows, restricted to a subset of the Version's own `version_counties`, and **mutually exclusive** across managers on the same Version (a given county maps to at most one Co-Registration Manager), enforced by a unique constraint on `co_registration_manager_counties.(version_id, county_id)` — the primary check happens in the Livewire component's validation (`Rule::in()` against the live assignable set), with the DB constraint as the backstop, not the first line of defense. **Built:** `co_registration_manager_counties` migration/model (`CoRegistrationManagerCounty`); `Version::coRegistrationManagerCounties()`; `VersionRoleAssignmentService::canManageCoRegistrationManagers()`, `assignCoRegistrationManager()`/`revokeCoRegistrationManager()` (role + county rows written atomically in a DB transaction); `GENERAL_ROLES_TAB_ROLES` (5 roles, Co-Registration Manager excluded) now backs `assignableRoleNames()` and the generic `assignRole()`/`revokeRole()` guard, which reject `Co-Registration Manager` with a 400 if called directly; the `VersionCoRegistrationManagers` Livewire screen (route `events.versions.co-registration-managers`) with user search-select, county checkboxes, and an inherit-current-selection edit flow; a new "Co-Registration Managers" button on the Event `Show` page, gated independently of `canManageEvent`/`canManageAuditionEnvironment`. 37 new/updated tests (`VersionRoleAssignmentServiceTest.php`, `VersionCoRegistrationManagersTest.php` new file, `ShowTest.php`); 718/718 passing app-wide, PHPStan clean. **Not built:** any downstream enforcement of a Co-Registration Manager's county scope elsewhere in the app (e.g. restricting which teachers/candidates they can see by county) — this phase is the assignment mechanism only, matching how `version_counties` itself has no enforcement beyond invitation eligibility.
