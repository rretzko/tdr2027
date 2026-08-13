<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();

            // PaymentSource: candidate_epayment | teacher_epayment | manual.
            $table->string('source');

            // Vendor: stripe | paypal. Null for source=manual.
            $table->string('vendor')->nullable();

            // Unique per vendor; null for manual entries. MySQL/Postgres both
            // treat NULL as distinct from NULL in a unique index, so any
            // number of (null, null) manual rows are allowed alongside this.
            $table->string('vendor_transaction_id')->nullable();

            $table->foreignId('payer_teacher_id')->nullable()->constrained('teachers')->nullOnDelete();

            // Unused until StudentFolder.info exists (see epayment-integration.md §0).
            $table->foreignId('payer_student_id')->nullable()->constrained('students')->nullOnDelete();

            // Denormalized snapshot at creation time, for pre-allocation triage
            // queries only (e.g. filtering "Needs Reconciliation" by school
            // before any candidate is linked). Reconciliation/balance math must
            // derive the school live via payment_allocations.candidate_id
            // instead — see epayment-integration.md §1.1/§3.
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();

            // Cents; the FULL transaction amount, not any one candidate's share.
            $table->unsignedInteger('amount');

            // PaymentTransactionStatus: pending | completed | failed | refunded.
            $table->string('status')->default('pending');

            // PaymentType (App\Enums\PaymentType, reused from teacher_payments/
            // candidate_payments): check | purchase_order | cash | other |
            // electronic. Only meaningful for source=manual — e.g. a
            // Registration Manager logging a Venmo/Zelle payment received
            // outside the app as "electronic" even though no gateway processed
            // it. Null for candidate_epayment/teacher_epayment rows, whose
            // vendor column already says how the money moved.
            $table->string('payment_type')->nullable();

            $table->string('reference_number')->nullable();
            $table->text('comments')->nullable();

            // The full webhook body, for audit — see epayment-integration.md §2.4.
            $table->json('raw_payload')->nullable();

            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Nullable: a checkout-session row is created with status=pending
            // *before* the money moves (§2.1), so paid_at isn't known yet —
            // it's set once the webhook flips status to completed. Only
            // source=manual rows (money already moved, teacher/RM entering it
            // by hand) set it at creation, same as today's candidate_payments.
            //
            // dateTime, not timestamp: see candidate_payments' migration for
            // why a required domain timestamp must avoid MySQL's implicit
            // "DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP" behavior
            // on this server (explicit_defaults_for_timestamp is OFF) — the
            // same risk applies here once paid_at is actually set.
            $table->dateTime('paid_at')->nullable();

            $table->timestamps();

            $table->unique(['vendor', 'vendor_transaction_id']);
            $table->index(['version_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
