<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill of the pre-existing candidate_payments/teacher_payments
 * rows into payment_transactions/payment_allocations — see
 * epayment-integration.md §1.1/§4 step 2.
 *
 * Deliberately does NOT drop candidate_payments/teacher_payments. Those
 * tables are still live — App\Livewire\Registrations\CandidateDetail's
 * manual-entry form keeps writing to candidate_payments until it's cut over
 * to the new schema (§4 step 5). This migration only needs to be safe to
 * re-run (idempotent) at that point, immediately before the cutover, to
 * pick up anything written in between; see this migration's own down() and
 * the re-run note below.
 */
return new class extends Migration
{
    public function up(): void
    {
        // candidate_payments -> one payment_transactions (source=manual) +
        // one payment_allocations row each, 100% allocated (§1.1) — these
        // were always known to belong to exactly one candidate.
        DB::table('candidate_payments as cp')
            ->join('candidates as c', 'c.id', '=', 'cp.candidate_id')
            ->select('cp.*', 'c.teacher_id as candidate_teacher_id', 'c.school_id as candidate_school_id')
            ->orderBy('cp.id')
            ->each(function (object $row): void {
                if ($this->alreadyBackfilled('candidate_payments', $row->id)) {
                    return;
                }

                $paymentTransactionId = DB::table('payment_transactions')->insertGetId([
                    'version_id' => $row->version_id,
                    'source' => 'manual',
                    'vendor' => null,
                    'vendor_transaction_id' => null,
                    'payer_teacher_id' => $row->candidate_teacher_id,
                    'payer_student_id' => null,
                    'school_id' => $row->candidate_school_id,
                    'amount' => $row->amount,
                    'status' => 'completed',
                    'payment_type' => $row->payment_type,
                    'reference_number' => $row->reference_number,
                    'comments' => $row->comments,
                    'raw_payload' => json_encode(['backfilled_from' => 'candidate_payments', 'id' => $row->id]),
                    'recorded_by_user_id' => null,
                    'paid_at' => $row->paid_at,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);

                DB::table('payment_allocations')->insert([
                    'payment_transaction_id' => $paymentTransactionId,
                    'candidate_id' => $row->candidate_id,
                    'amount' => $row->amount,
                    'allocated_by_user_id' => null,
                    'allocated_at' => $row->paid_at,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            });

        // teacher_payments -> one payment_transactions (source=manual) each,
        // with NO allocations — these were never known to belong to specific
        // candidates, so they surface in the "Needs Reconciliation" queue
        // for the first time (§1.1, an intended one-time backlog).
        //
        // teacher_payments has no paid_at column of its own; created_at (when
        // the Registration Manager entered it via the modal) is the closest
        // available proxy for "when the money actually moved".
        DB::table('teacher_payments')
            ->orderBy('id')
            ->each(function (object $row): void {
                if ($this->alreadyBackfilled('teacher_payments', $row->id)) {
                    return;
                }

                DB::table('payment_transactions')->insert([
                    'version_id' => $row->version_id,
                    'source' => 'manual',
                    'vendor' => null,
                    'vendor_transaction_id' => null,
                    'payer_teacher_id' => $row->teacher_id,
                    'payer_student_id' => null,
                    'school_id' => $row->school_id,
                    'amount' => $row->amount,
                    'status' => 'completed',
                    'payment_type' => $row->payment_type,
                    'reference_number' => $row->reference_number,
                    'comments' => $row->comments,
                    'raw_payload' => json_encode(['backfilled_from' => 'teacher_payments', 'id' => $row->id]),
                    'recorded_by_user_id' => $row->recorded_by_user_id,
                    'paid_at' => $row->created_at,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            });
    }

    /**
     * Makes this migration safe to re-run (per §4 step 5's "re-diff the
     * backfill" requirement) by checking the raw_payload marker this
     * migration itself writes, rather than relying on migration-run
     * bookkeeping alone.
     */
    private function alreadyBackfilled(string $table, int $sourceId): bool
    {
        return DB::table('payment_transactions')
            ->where('raw_payload', json_encode(['backfilled_from' => $table, 'id' => $sourceId]))
            ->exists();
    }

    public function down(): void
    {
        DB::table('payment_transactions')
            ->where('raw_payload', 'like', '%"backfilled_from"%')
            ->delete();
    }
};
