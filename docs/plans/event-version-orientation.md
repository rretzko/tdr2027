# TheDirectorsRoom.com — TDR2027
# Event & Version Domain — Orientation & Build Specification

**Audience:** Claude Code (PhpStorm) and human reviewers.
**Status:** In-scope build (§0.2 "Build now") implemented and tested, including Version Invitations (§5.4) and Version Pitch Files (§5.5). Verified against the codebase 2026-07-09: 500/500 Feature/Unit tests passing app-wide. Reference-only items (§7, adjudication/tab room/cut-offs) remain intentionally unbuilt. See §9 — all tracked items are resolved except intentionally-deferred sub-features.

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

### 5.3 Version roles (version-scoped)

Roles are granted relative to the Version via Spatie. The defined roles are:

- Event Manager
- Registration Manager
- Co-Registration Manager
- Web Registration Manager
- Tab Room Manager
- Rehearsal Manager

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

`invited | obligated | participating` — no `eligible` case; absence of a `version_invitations` row is the eligible state. See §5.4. Default on row creation: `invited`. `obligated` and `participating` are reserved — set only by the not-yet-built registration workflow, not by this phase.

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
9. **Still intentionally deferred within §5.4** (flagged, not scaffolded): search/filter/pagination on the invitation roster, email notification on invite, and any UI for the `obligated`/`participating` transitions — these belong to the not-yet-designed registration workflow.
10. ~~**Version Pitch Files (§5.5) — new capability, not yet built.**~~ Resolved — built and committed in `b70abb3 "Add: Initial pitch files functionality."` and `2a55580 "Updt: Completed pitch files functionality."`: `VersionPitchFile` model + migration (`version_pitch_files`), the `VersionPitchFiles` Livewire component + view (search/filter/sort/drag-reorder), S3-backed file storage with delete-on-replace and delete-on-remove, and feature test coverage (`VersionPitchFilesTest`, 14 tests). `voice_part_id` is validated against `Version::availableVoiceParts()`. Gated by the same `canManageEvent` check as `VersionEdit`/`VersionInvitations`. `VoicePartSeeder` gained an `'ALL'` voice part (for files that apply across parts) as part of this work; `SeedersTest` was updated from 17 to 18 expected voice parts to match.
11. **Still intentionally deferred within §5.5** (flagged, not scaffolded): enforcement of `pitch_file_visibility` (teacher/candidate/both) and the post-close visibility windows, and any candidate/teacher-facing display of pitch files on StudentFolder.info — this phase is Event Manager CRUD only.
