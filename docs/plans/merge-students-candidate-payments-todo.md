# To-do: MergeStudents loses candidates and payment allocations

Status: **fixed** (logged 2026-10-26; first fix 634d1ae on main 2026-10-30, same-version rule from the 2026-10-27 WIP merged in on `feature/merge-students-same-version-rule`)

## Problem

`App\Livewire\Founder\MergeStudents::performMerge()` reassigns `school_student`,
`student_teacher`, emergency contacts, etc. to the winner, but **never touches
`candidates`**. It then calls `$loser->delete()` (`app/Livewire/Founder/MergeStudents.php:218`),
which triggers this FK chain:

| FK | On delete | Effect |
|---|---|---|
| `candidates.student_id` → `students` | cascade | loser's candidate rows deleted |
| `payment_allocations.candidate_id` → `candidates` | cascade | allocations on those candidates deleted |
| `payment_transactions.payer_student_id` → `students` | set null | loser's payments orphaned (`payer_student_id` = NULL) |

Anything else that cascades from `candidates` (recordings, etc.) is lost the same way.

The teacher Registrations page (`VersionDashboard`) shows Paid amounts and the
payment register via `payment_allocations.candidate_id`, **not** `payer_student_id`.
So restoring `payer_student_id` alone does not make the payment visible again.
Student-paid e-payments also never show in "Unreconciled Payments", because that
panel filters on `payer_teacher_id`.

## Incident: payment_transactions.id = 504

- v88, Square, $28.00, loser student 21105 → winner student 20727, school 2584, teacher users.id 285
- `payer_student_id` restored to 20727 manually
- Allocation still missing. Restore it in prod (confirm the candidate id first):

```sql
SELECT id FROM candidates WHERE version_id = 88 AND student_id = 20727;  -- local: 888720

INSERT INTO `payment_allocations`
  (`payment_transaction_id`, `candidate_id`, `amount`, `allocated_by_user_id`, `allocated_at`, `created_at`, `updated_at`)
VALUES
  (504, 888720, 2800, NULL, '2026-10-24 20:55:00', NOW(), NOW());
```

- [x] Run the restore in prod and verify on teacher 285's v88 Registrations page (done 2026-10-27; allocation id 679 confirmed on local prod copy)

## Fix in `performMerge()` (feature branch off dev)

- [x] Reassign `payment_transactions.payer_student_id` loser → winner
- [x] Reassign the loser's `candidates` to the winner when the winner has no candidate in that version (plus `scores.student_id`, which also cascades on Student delete)
- [x] When both have a candidate in the same version: decide which one survives and move
      `payment_allocations` (and other candidate children) onto it before deleting the other
- [x] Audit all other FKs to `candidates` / `students` with `cascadeOnDelete()` for similar data loss
- [x] Apply the same handling to the `MergeStudents` console command (`app/Console/Commands/MergeStudents.php`)
- [x] Tests: merge with a paid candidate on the loser keeps the allocation and the dashboard Paid amount
- [x] Look for other past merges with orphaned payments:
      `SELECT * FROM payment_transactions WHERE payer_student_id IS NULL AND payer_teacher_id IS NULL;`
      and transactions where the allocated total is less than `amount`

## Resolution (2026-10-27)

Both entry points now call `App\Services\StudentMergeService`.

- Same-version conflict rule (user decision): the winner's Candidate survives and the
  loser's `payment_allocations` move onto it. If the loser's Candidate there has
  `scores`, `recordings`, `candidate_upload_files` or `audition_results`, the merge is
  refused before any write. The Founder page shows a danger toast naming the Version(s)
  and the command exits FAILURE.
- FK audit (local prod copy): cascades from `candidates` are audition_results,
  candidate_payments (legacy, empty), candidate_status_history, candidate_upload_files,
  payment_allocations, recordings, scores. From `students`: candidates and scores cascade,
  payment_transactions sets null, the rest restrict. All are now handled.
- History check: the only payerless transaction is 499, an *expired* $28 v88 checkout
  with nothing paid. No completed transaction is under-allocated, so no other merge lost money.
- 64 same-name groups have Candidates on both sides in one Version (37 with payments),
  so the conflict path will be exercised.

## Final shape (2026-10-30)

The 2026-10-27 WIP sat in a stash while a separate fix (634d1ae) shipped to main. That fix
refused *every* same-Version pair; this branch restores the rule above and keeps what 634d1ae added:

- Before the loser's User is deleted, `recordings.uploaded_by` (CASCADE),
  `candidate_status_history.user_id` (RESTRICT — would roll back the whole merge),
  `candidate_upload_files.uploaded_by_user_id` and `payment_transactions.recorded_by_user_id`
  move to the winner's User.
- The winner's null `birthday`/`height`/`instrument_id`/`voice_part_id`/`home_school_id`
  are filled from the loser.
- `assertMergeable()` lets the command refuse before its confirmation prompt.
