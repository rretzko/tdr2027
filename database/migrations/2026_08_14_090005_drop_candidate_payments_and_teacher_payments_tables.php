<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ships in the same deploy as CandidateDetail/ParticipatingSchools/
 * PaymentRoster's cutover to payment_transactions/payment_allocations (§4
 * step 5) — never split across deploys, per the sequencing fix in
 * epayment-integration.md §1.1/§4: dropping these tables before every live
 * reader/writer is migrated would either break those features outright or,
 * if the drop were deferred, silently lose data written in the gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Re-run the original backfill one more time, immediately before
        // dropping, to catch anything written to the old tables in the
        // window between it (migration 2026_08_14_090003) and this cutover
        // — see that migration's own docblock for why it's safe to re-run
        // (idempotent via the raw_payload marker it checks).
        (require database_path('migrations/2026_08_14_090003_backfill_candidate_and_teacher_payments_into_payment_transactions.php'))->up();

        Schema::dropIfExists('candidate_payments');
        Schema::dropIfExists('teacher_payments');
    }

    public function down(): void
    {
        // Schema only — data is not restored (candidate_payments/
        // teacher_payments rows already backfilled into payment_transactions
        // are unaffected by this rollback and stay there).
        Schema::create('teacher_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->string('payment_type');
            $table->unsignedInteger('amount');
            $table->string('reference_number')->nullable();
            $table->text('comments')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('candidate_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->unsignedBigInteger('candidate_id');
            $table->foreign('candidate_id')->references('id')->on('candidates')->cascadeOnDelete();
            $table->string('payment_type')->default('electronic');
            $table->unsignedInteger('amount');
            $table->string('reference_number')->nullable();
            $table->text('comments')->nullable();
            $table->dateTime('paid_at');
            $table->timestamps();
        });
    }
};
