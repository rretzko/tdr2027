<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_transaction_id')->constrained('payment_transactions')->cascadeOnDelete();

            // candidates.id is a custom unsignedBigInteger PK (not
            // bigIncrements), same explicit foreign() pattern as
            // candidate_payments/candidate_status_history/scores.
            $table->unsignedBigInteger('candidate_id');
            $table->foreign('candidate_id')->references('id')->on('candidates')->cascadeOnDelete();

            // Cents; this candidate's portion of the parent transaction.
            // Never exceeding payment_transactions.amount in aggregate is
            // enforced in the allocation service, not a DB constraint — see
            // epayment-integration.md §1.1.
            $table->unsignedInteger('amount');

            $table->foreignId('allocated_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // dateTime, not timestamp: same MySQL implicit-ON-UPDATE risk as
            // payment_transactions.paid_at (see that migration's comment).
            $table->dateTime('allocated_at');

            $table->timestamps();

            // No uniqueness constraint on (payment_transaction_id,
            // candidate_id): §1.1 allows allocating to the same transaction
            // "in any number of passes", including multiple partial
            // allocations to the same candidate over time — each row is its
            // own audited event, not a running total to upsert.
            $table->index(['payment_transaction_id', 'candidate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
