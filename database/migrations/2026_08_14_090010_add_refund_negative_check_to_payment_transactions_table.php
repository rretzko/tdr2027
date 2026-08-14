<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Belt-and-suspenders for the sign convention CandidateDetail::savePayment()
 * already enforces in PHP: only a payment_type=refund row may carry a
 * negative amount. COALESCE guards payment_type IS NULL (candidate_epayment/
 * teacher_epayment rows) — a bare `payment_type = 'refund'` comparison
 * evaluates to NULL there, and a CHECK is only violated on FALSE, so an
 * unguarded negative amount would slip through unnoticed.
 *
 * payment_allocations has no payment_type of its own (it inherits the sign
 * from its parent payment_transactions row via application code, not a
 * column CHECK constraints could see), so it isn't covered here.
 *
 * MySQL-only, matching 2026_08_14_090009 — SQLite (the test suite's driver)
 * has no CHECK constraint enforcement worth replicating here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            'ALTER TABLE payment_transactions ADD CONSTRAINT chk_payment_transactions_refund_negative '.
            "CHECK (amount >= 0 OR COALESCE(payment_type, '') = 'refund')"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE payment_transactions DROP CONSTRAINT chk_payment_transactions_refund_negative');
    }
};
