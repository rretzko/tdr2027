# Plan: Event Manager director CSV upload (Invitations page)

## Goal

Let an Event Manager upload a CSV of directors from the Version's Invitations
page. For each row the system finds or creates the `User`, `Teacher`, `School`
and `school_teacher` link, then sets the director to **Invited** for that
Version, without creating duplicate records.

## Decisions (confirmed 2026-10-18)

| # | Question | Decision |
|---|----------|----------|
| 1 | Verification | The Event Manager vouches for the director: `school_teacher.is_active = true`, `verified_at = now()`. The director is eligible right away and appears on the roster as Invited. |
| 2 | Notifications | **None.** Records are created silently. New directors get in through "Forgot password" or social login, and the Event Manager tells them about it. |
| 3 | Possible duplicates | **Two-step flow: preview, then confirm.** Nothing is written until the Event Manager resolves the flagged rows and confirms. |
| 4 | Existing teacher, different school | **Add the school link** (active + verified, per #1) next to the teacher's existing schools, then invite. |

Assumption to confirm: new teachers get `onboarding_step = 3`, so their first
login resumes the wizard at Students and skips School + Role/Subjects, which
the upload already captured. Without this, the wizard would ask for the
school again and could create a second school link.

## 1. CSV template

File: `resources/templates/director-upload-template.csv`. The Invitations
page serves it through a "Download template" link.

| Column | Required | Rules / matching |
|---|---|---|
| `honorific` | no | ≤ 50 chars (Mr., Ms., Dr., …) |
| `first_name` | **yes** | ≤ 255 |
| `middle_name` | no | ≤ 255 |
| `last_name` | **yes** | ≤ 255 |
| `suffix_name` | no | ≤ 50 |
| `pronoun` | no | Matched case-insensitively to `pronouns.personal` (`she`, `he`, `they`, …) or the full `description`. Blank stays null (`users.pronoun_id` is nullable). An unknown value is a row error. |
| `email` | **yes** | RFC email. **Main identity key**, matched case-insensitively and trimmed against `users.email`. |
| `cell_phone` | no | Normalized by `PhoneNormalizer`. Must be 10 digits after normalizing. |
| `school_name` | **yes** | With `school_zip`, the school identity key. |
| `school_type` | no | `school` (default) or `studio` |
| `school_city` | **yes** | Used only when a new school is created |
| `school_zip` | **yes** | 5 digits. Leading zeros are restored if Excel dropped them (`8608` → `08608`). |
| `school_county` | **yes** | County name within `school_state`. "Mercer" and "Mercer County" both match. |
| `school_state` | no | 2-letter abbreviation or full name. Default `NJ`. |
| `school_email` | no | Stored on the `school_teacher` link. Also used as a duplicate signal (see §3). |
| `role` | no | `primary` (default) or `coteacher` |
| `subjects` | no | Pipe-separated: `band\|chorus\|orchestra` |

Header rules: header names must match exactly after trim and lower-case;
column order doesn't matter. A missing required header rejects the whole
file. Unknown headers are ignored, with a notice. Limits: `.csv`/`.txt`,
≤ 1 MB, ≤ 500 data rows. A UTF-8 BOM is stripped. Fully blank rows are
skipped.

## 2. Architecture

```
VersionInvitations (existing page)
 ├─ "Upload Directors" button → flux:modal hosting
 │    App\Livewire\Events\DirectorUpload  (new, WithFileUploads)
 │      step 1: upload → DirectorUploadService::preview()
 │      step 2: resolve flagged rows, Confirm → DirectorUploadService::commit()
 │      dispatches 'directors-uploaded' → parent re-renders roster
 └─ "Download template" → DirectorUploadTemplateController (route, canManageEvent gate)

App\Support\DirectorUpload\
  DirectorCsvParser         headers/limits/normalization → list<DirectorUploadRow>
  DirectorUploadRow         readonly DTO (normalized input + line number)
  DirectorRowPreview        DTO: row, outcome, matched ids, warnings, choices
  DirectorRowOutcome        enum (see §3)
App\Services\DirectorUploadService
  preview(Version, rows): Collection<DirectorRowPreview>   — read-only
  commit(Version, rows, resolutions, User): DirectorUploadResult
```

- Read the uploaded file through `$file->get()`, not a local path. On Vapor,
  Livewire temporary uploads are stored on S3.
- `commit()` **re-runs matching** inside the transaction instead of trusting
  the preview, because a record could be created between preview and confirm.
  Any row whose outcome changed is reported, not forced through.
- Each row is committed in its own `DB::transaction()`, so one bad row
  doesn't roll back the rest. Every write goes through Eloquent `create()`
  or `update()`, never `updateExistingPivot()`, so `SchoolTeacherObserver`
  and `VersionInvitationObserver` (auto-enrollment) both fire.
- Authorization: `canManageEvent()` in `mount()` and again in every action,
  the same as the existing `toggle`/`inviteAll` actions.
- After a commit that changed anything: `markReadinessReviewed()` plus a
  personalized `Flux::toast` (e.g. "12 directors invited to {version}: 5 new
  accounts, 2 new schools.").

## 3. Matching & de-duplication rules

Rows are resolved in order. A cache inside the file (keyed by normalized
email, and by school name + zip) means two rows in the same CSV share one
new User or School instead of creating two.

### 3a. School
1. Resolve the state (default NJ) and the county within it. Not found → **Error**.
2. **Exact match:** `lower(trim(name))` + `zip_code` (mirrors the
   `unique(name, zip_code)` index) → reuse that school.
3. Otherwise run `SchoolMatcher::suggestions(name, state, zip, county, type)`.
   Any suggestion ≥ 70% → **Possible duplicate (school)**. The Event Manager
   picks one of the suggestions or chooses "Create new school".
4. Otherwise → a new school will be created.
5. **Eligibility check:** if the Version has counties configured and the
   school's county isn't one of them → **Error: "school not in this Version's
   counties."** An invitation for this director would never appear on the
   roster.

### 3b. Person
1. **Email matches an existing user**
   - has a `Teacher` → existing teacher (no new User or Teacher)
   - has a `Student` only → **Error** ("email belongs to a student account")
   - neither → reuse the User and add a `Teacher` profile and the `Teacher` role
   - Existing users' name, pronoun and phone are **never overwritten**. Any
     differences are listed as informational warnings.
2. **No email match. Look for duplicate signals:**
   - the `cell_phone` belongs to another user
   - the `school_email` matches an existing `school_teacher.school_email`
   - the same first + last name (case-insensitive) is a teacher already
     linked to the resolved school

   Any hit → **Possible duplicate (person)**, showing the name, school and
   masked email of the match. The Event Manager chooses "Use existing
   teacher" or "Create new director". If they create new while the phone
   conflicts, the phone is dropped, because `users.cell_phone` is unique.
3. Otherwise → a new User + Teacher will be created.

### 3c. School link & invitation
- No link to this school → create one: `role` and `subjects` from the CSV,
  `is_active = true`, `verified_at = now()`, `school_email`. This covers
  decision #4.
- Link exists but is inactive or unverified → update it to active +
  verified. The preview warns "will reactivate / verify".
- Link exists, active and verified → unchanged. Subjects in the CSV that
  aren't on the link yet are added, never removed.
- An invitation for this Version already exists → **Already invited
  ({status})**, nothing is written. Otherwise create a `VersionInvitation`:
  `status = invited`, `invited_by_user_id = Auth::id()`.

### Preview outcomes (`DirectorRowOutcome`)
| Outcome | Badge | Blocks confirm? |
|---|---|---|
| `New` (new user/teacher, maybe new school) | green | no |
| `Existing` (matched teacher, will link/invite) | blue | no |
| `AlreadyInvited` | zinc | no (skipped) |
| `PossibleDuplicate` | amber | **yes, until resolved** |
| `Error` | red | no (row skipped, reason shown) |

Running the same file twice must produce zero new records the second time:
every row becomes `AlreadyInvited`.

## 4. UI (Flux)

- Header buttons on `version-invitations.blade.php`: **Upload Directors**
  next to Invite All / Remove All, plus a "Download template" link inside
  the modal.
- Modal step 1: `flux:input type="file"` (accepts `.csv`), a column cheat
  sheet, Preview button, and a header/limit error callout.
- Modal step 2: outcome summary tiles (New / Existing / Already invited /
  Needs review / Errors) and a preview table that follows the responsive
  standard (cards below `md:`, full `flux:table` at `md:`+). Each Possible
  duplicate row gets a `flux:radio.group` of choices. Confirm stays disabled
  until every flagged row is resolved. A bottom-of-form `$errors->any()`
  summary is included.
- After commit: a result summary in the modal plus the toast. The roster
  refreshes through the `directors-uploaded` event.

## 5. Implementation steps

1. Routes + `DirectorUploadTemplateController` (download, gated).
2. `DirectorCsvParser` + DTOs + `DirectorRowOutcome` enum.
3. `DirectorUploadService::preview()` with the matching rules in §3.
4. `DirectorUploadService::commit()` (re-match, per-row transaction,
   resolutions applied).
5. `DirectorUpload` Livewire component + Blade, modal wiring on
   `VersionInvitations`.
6. Onboarding: new teachers get `onboarding_step = 3`, after checking that
   wizard step 3 works from a mount with an existing link (the wizard's
   `currentSchoolTeacher()`).
7. Pest tests (§6), `composer dump-autoload`, PHPStan, Pint.

## 6. Tests (Pest)

- Parser: missing required header, unknown header ignored, BOM, Excel zip
  without leading zero, blank rows, >500 rows, pipe subjects, bad enum values.
- School: exact match reused, fuzzy flags a possible duplicate, new school
  created once even when shared by multiple rows, unknown county is an
  error, out-of-county is an error.
- Person: email match on an existing teacher (no new User/Teacher, profile
  not overwritten), email match on a student is an error, a user with no
  teacher gets a teacher profile, phone / school_email / name-at-school
  each trigger a possible duplicate, a duplicate email within the file is
  an error on the second row.
- Link: new link created active+verified, an inactive link is reactivated,
  an existing teacher at another school gets the extra link, subjects are
  added without removing existing ones.
- Invitation: created with `invited_by_user_id`, the existing one is
  untouched, uploading twice is idempotent, the observer auto-enrollment
  fires for an Active Version.
- Commit re-match: a record created between preview and commit is reported,
  not duplicated.
- Auth: a non-manager gets 403 on mount, upload, commit and template download.
- Use helper names unique to the test file. Pest helper functions are
  global across the suite.
