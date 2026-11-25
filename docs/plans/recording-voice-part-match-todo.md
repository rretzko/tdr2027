# Recording voice-part match: to-dos

Written 2026-11-25. Items 1-6 (stamps, checklist gate, change decisions, part
confirmation, part named at upload, "Approve as …") were released 2026-11-24:
commit `fcc348b`, dev `4ea197b`, main `1dafa29`. The migration
(`2026_11_24_000001`) is run locally, on staging and on production.

## Rules to keep

- Change `candidates.voice_part_id` only through `VoicePartChangeService`.
- A NULL recording stamp means unknown: never a mismatch, never backfilled.
- A teacher changing the part after approval may answer "Yes, these match".
- No automatic pitch detection: neighboring parts overlap too much.

## Verify (nothing to build)

- [ ] In production, open a real candidate in the teacher portal and in the
      student portal; check the confirm, upload and approve steps. Only the
      deployed code, columns and login page were checked.
- [ ] Confirm the GitHub "Tests" run for `1dafa29` passed; it was still running
      when last checked. The full suite passed locally (2,285).

## Planned, not approved to build

Numbers are from the original 12-item recommendation.

- [ ] **7. Default teachers to the right pitch file.** Link to the filtered list
      from each candidate's page; start the general page with no part selected
      (`Registrations/PitchFiles.php` shows every part by default).
- [ ] **8. Flag voice parts in file names.** Extend
      `RecordingReviewService::filenameFlagReason()`; match the longest name
      first so "Alto I" and "Alto II" aren't confused. Warning only.
- [ ] **9. "Wrong voice part?" button for judges** on Adjudicate. Alerts the
      Event Manager and Tab Room without moving the candidate.
- [ ] **10. Filter Voice Part Audit by risk:** stamp mismatch or unknown, part
      never confirmed, part changed after upload, or file name flagged.
      ("Changed after upload" may need item 11.)
- [ ] **11. Voice-part history** (from, to, who, when), shown on the
      Registration History tab.
- [ ] **12. Readiness check for pitch files per part.** One file for the whole
      Version currently counts as Done.

## Leftovers

- [ ] `RepairCandidateVoicePartGrade` updates `voice_part_id` directly,
      bypassing the service (no re-stamp, removal or confirmation date).
- [ ] Tab Room "Yes, they match" still deletes non-relevant Recordings but leaves
      their uploads approved (`AdjudicationService::changeVoicePart()`).
- [ ] `DownloadsTest` is flaky: faker names can collide as substrings. Failed
      once, then passed 3 reruns and the full run. Unrelated to this feature.
