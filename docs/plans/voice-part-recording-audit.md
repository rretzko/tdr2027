# Voice Part ↔ Recording Audit (Founder page)

**Status:** built 2026-11-11. Founder page, plus a **Voice Part Audit tab on Web Registration** (branch `feature/web-registration-voice-part-audit`). See "Web Registration tab" below · **Original branch:** `feature/voice-part-recording-audit` (off `dev`)

## Why

Version 93 had 94 Candidates whose voice part wasn't offered to their grade. Two things caused it: auto-enrollment ignored grade before commit `064ad5b` (2026-09-21), and Descant was removed from the event after the bulk enrollment. `candidates:voice-part-mismatches` lists these Candidates, but checking a Candidate's voice part against what the student actually sang meant opening each Candidate page one at a time. This page puts every approved recording next to its voice part on one screen, so they can be checked by ear and corrected in place.

It's Founder-only for now. It's built so it can move to Event Manager / Registration Manager permissions later: the logic lives in a service, and authorization lives in one method.

## Decisions (confirmed with the user)

| Question | Decision |
|---|---|
| Voice parts in the edit dropdown | **Grade-offered only**: `Version::availableVoicePartsForGrade($student->grade)`, the same rule as the teacher modal and auto-enrollment |
| Which rows | **Candidates with ≥1 teacher-approved recording** (`candidate_upload_files.status = approved`) |
| Candidate already has scores | **Plain update; refuse if scored.** Point to Tab Room → Add/Edit Scores → Change Voice Part. This page never deletes anything. |
| Playback | **Inline `<audio preload="none">`** per approved recording, using signed S3 `temporaryUrl()` (same as `adjudicate.blade.php`) |

Recording source: use `candidate_upload_files` (it has the slot name via `versionUploadFile`, `original_filename`, `status`, and `url`). Approved rows are mirrored into `recordings` (511 = 511 on v93), but `candidate_upload_files` is the teacher-approval source of truth and carries the slot label.

## Page

**Route:** `GET /founder/voice-part-audit` → `App\Livewire\Founder\VoicePartAudit`, named `founder.voice-part-audit`, inside the existing `['auth', 'verified', 'founder']` group. Add a sidebar item under the Founder group (icon `musical-note`).

**Controls (top bar, all `wire:model.live`, kept in the URL with `#[Url]`):**

- **Version** `flux:select`: Versions that have Candidates, newest first, labeled "Event — Version". Nothing below loads until one is picked.
- **Status** `flux:select`: "Active (Eligible/Pending/Registered)" (default), "All", or a single status.
- **Voice part** `flux:select`: all, or one voice part.
- **Grade mismatch only** `flux:switch`: shows only rows whose voice part isn't offered to the grade.
- **Search**: student or teacher name.

**Table** (`flux:table`, following the responsive standard: cards below `md:`, full table at `md:`+):

| Column | Content |
|---|---|
| Candidate | `candidates.id` (links to the Candidate's teacher-side detail page, for context) |
| Student | Last, First · grade · status badge |
| Teacher | Last, First · school |
| Voice part | `flux:select` of grade-offered parts, with the current part selected. If the current part isn't offered: a red "Not offered for grade N" badge and an empty select (same pattern as the teacher modal fix) |
| Recordings | One compact block per approved upload: slot name (Scales, Solo…), original filename (small, muted), `<audio controls preload="none">` |

**Sort:** initially by `voice_parts.sort_order` (the request said "order_by"; the column on `voice_parts` is `sort_order`), then student last and first name. Sortable column headers for Voice part, Student, and Teacher. Paginate at 25 rows. Signed URLs are generated only for the current page.

**Editing:** choosing a new value calls `changeVoicePart(candidateId, voicePartId)` directly (`wire:change`). Within that call:

1. Authorize again (`authorizeAudit()`) and confirm the Candidate belongs to the selected Version.
2. Validate that `voicePartId` ∈ `availableVoicePartsForGrade(grade)`.
3. Refuse if `Score::where('candidate_id', …)->exists()`, with a toast pointing to Tab Room.
4. `$candidate->update(['voice_part_id' => …])`, then `CandidateService::recalculateStatus($candidate, $this->checklistDefs($version))` using the `HasCandidateChecklist` trait.
5. Show a `Flux::toast("{Student}: Alto → Alto I")` (per the CRUD toast rule). The row re-renders with the new value. A row that no longer matches the active filters (e.g. "mismatch only") stays visible until the next filter change or page change, so it doesn't vanish under the cursor.

Errors from step 2 or 3 are a toast, and the select reverts. Following the guarded-action rule, the select is never pre-disabled for scored Candidates: the refusal is explained after the change.

## Code layout (for the later EM/RM move)

- **`App\Services\VoicePartAuditService`** holds all the logic, with no auth:
  - `rows(Version, filters, sort): LengthAwarePaginator` builds one query: Candidates `whereHas('uploadFiles', approved)`, eager-loading `student.user`, `student.schools`, `teacher.user`, `school`, `voicePart`, and `uploadFiles` (approved only) with `versionUploadFile`. It joins `voice_parts` for the sort.
  - `offeredVoicePartsByGrade(Version, grades)` is memoized per grade, the same as the console command.
  - `changeVoicePart(Candidate, VoicePart): void` covers steps 2–4 above and throws a domain exception for "not offered" and "has scores". It's separate from `AdjudicationService::changeVoicePart()`, which is the destructive Tab Room path.
- **`VoicePartAudit` component:** UI state, plus one `authorizeAudit(Version)` method. Today it's `abort_unless(Auth::user()->isFounder(), 403)` (route middleware already enforces this; the method keeps action calls safe too).
- **Later move:** add a route under `/events/versions/{version}/…`, swap `authorizeAudit()` to `VersionRoleAssignmentService::canManageReports()` and use the `ScopesReports` county scoping (filter rows by school county), and drop the Version picker in favor of the route-bound Version. The service and view are reused unchanged.
- Optionally, `candidates:voice-part-mismatches` could reuse the service's per-grade helper. That's not required for this feature.

## Tests (Pest, `tests/Feature/Livewire/Founder/VoicePartAuditTest.php`)

Use unique helper-function names (Pest helper functions are global across the whole suite).

1. Non-Founders get 403 on the route.
2. No Version selected → no table, plus a prompt.
3. Lists only Candidates with ≥1 **approved** upload. Pending-only uploads are excluded. Approved uploads show, and pending ones don't.
4. Default sort is by voice part `sort_order`, then student name.
5. The mismatch filter shows only Candidates whose voice part isn't offered to their grade. The status filter works, with Active as the default.
6. `changeVoicePart` to an offered part updates the Candidate and recalculates status.
7. `changeVoicePart` to a part not offered for the grade is rejected, and the Candidate is unchanged.
8. `changeVoicePart` on a Candidate with Scores is rejected, the Candidate is unchanged, and no Score or Recording is deleted.
9. `changeVoicePart` with a Candidate from a different Version than the one selected is rejected.
10. Unit-style tests for `VoicePartAuditService::changeVoicePart` exceptions.

Then: `composer dump-autoload`, Pint, the affected tests, and full-project PHPStan (no path arguments). The user does browser verification.

## Web Registration tab (built 2026-11-11)

The audit is also a fourth tab, **Voice Part Audit**, on `events.versions.web-registration`.

- **Components:** `Shared\AbstractVoicePartAudit` holds all the UI behavior. `Founder\VoicePartAudit` (Version picker, every county) and `Events\VersionVoicePartAudit` (embedded with `lazy`, `#[Locked]` versionId) extend it. The shared views live in `resources/views/livewire/voice-part-audit/`.
- **Access** (confirmed with the user): `VersionRoleAssignmentService::canAuditVoiceParts()` = `canManageWebRegistration()` **or** `canManageReports()`. Registration Managers and Co-Registration Managers can now open Web Registration, but they see **only** this tab. `WebRegistration::$fullAccess` (locked) hides the other three tabs and skips loading their data. Their actions still re-check `canManageWebRegistration()`. This intentionally reverses the earlier rule that Registration Managers get a 403 on Web Registration. The Event page's Web Registration button now uses `canAuditVoiceParts()`.
- **County scope:** `voicePartAuditCountyIds()` returns null (every county) for anyone with Web Registration access, and otherwise `reportCountyIds()`. Without that, a Web Registration Manager who isn't a Registration Manager would get `[]` and see nothing. Co-Registration Managers are limited to their counties for both listing and changes.

## Out of scope / follow-ups
- Proactive flagging: a Registration Checklist item for "voice part not offered for grade", and a warning when an Event Manager removes a voice part from an Ensemble that Candidates are using. These are the two remaining gaps that can create new mismatches.
- An audit trail of voice-part changes. There's no candidate voice-part history table today. Status history records status changes only.
