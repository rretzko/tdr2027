<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();

            // candidates.id is a custom unsignedBigInteger PK (not bigIncrements),
            // same explicit foreign() pattern as candidate_status_history/scores.
            $table->unsignedBigInteger('candidate_id');
            $table->foreign('candidate_id')->references('id')->on('candidates')->cascadeOnDelete();

            // Schema/report-only this phase — no writer exists yet, as there is
            // no candidate-facing e-payment integration built (see §5.10/§8.15).
            $table->string('payment_type')->default('electronic');

            $table->unsignedInteger('amount');
            $table->string('reference_number')->nullable();
            $table->text('comments')->nullable();

            // dateTime, not timestamp: with explicit_defaults_for_timestamp OFF on
            // this server, a required (NOT NULL) TIMESTAMP column with no explicit
            // default silently becomes DEFAULT CURRENT_TIMESTAMP ON UPDATE
            // CURRENT_TIMESTAMP — meaning any later update() to this row would
            // silently rewrite the real payment date to "now". DATETIME has no such
            // implicit behavior in MySQL, so the column stays genuinely required
            // with no default and no auto-touch-on-update.
            $table->dateTime('paid_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_payments');
    }
};
