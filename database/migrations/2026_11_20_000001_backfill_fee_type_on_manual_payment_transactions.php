<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Manual payments (CandidateDetail::savePayment(), ParticipatingSchools::
 * savePayment()) were written without a fee_type, so every fee_type-scoped
 * "Paid" total (Scorecard, Director Weekly Update) ignored them. Both forms
 * only ever record registration fees, so stamp the existing rows to match
 * what the forms now write.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('payment_transactions')
            ->where('source', 'manual')
            ->whereNull('fee_type')
            ->update(['fee_type' => 'registration']);
    }

    /**
     * Not reversible: after up() there's no way to tell a backfilled row
     * from one the form wrote with fee_type set.
     */
    public function down(): void {}
};
