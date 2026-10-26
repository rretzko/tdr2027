# To-do: MergeStudents loses candidates and payment allocations

Status: **open** (logged 2026-10-26)

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

- [ ] Run the restore in prod and verify on teacher 285's v88 Registrations page

## Fix in `performMerge()` (feature branch off dev)

- [ ] Reassign `payment_transactions.payer_student_id` loser → winner
- [ ] Reassign the loser's `candidates` to the winner when the winner has no candidate in that version
- [ ] When both have a candidate in the same version: decide which one survives and move
      `payment_allocations` (and other candidate children) onto it before deleting the other
- [ ] Audit all other FKs to `candidates` / `students` with `cascadeOnDelete()` for similar data loss
- [ ] Apply the same handling to the `MergeStudents` console command (`app/Console/Commands/MergeStudents.php`)
- [ ] Tests: merge with a paid candidate on the loser keeps the allocation and the dashboard Paid amount
- [ ] Look for other past merges with orphaned payments:
      `SELECT * FROM payment_transactions WHERE payer_student_id IS NULL AND payer_teacher_id IS NULL;`
      and transactions where the allocated total is less than `amount`
