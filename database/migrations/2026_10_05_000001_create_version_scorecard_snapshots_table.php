<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_scorecard_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->date('captured_on');
            $table->unsignedInteger('invited_teachers');
            $table->unsignedInteger('invited_schools');
            $table->unsignedInteger('eligible_students');
            $table->unsignedInteger('obligated_teachers');
            $table->unsignedInteger('obligated_schools');
            $table->unsignedInteger('engaged_students');
            $table->unsignedInteger('registered_teachers');
            $table->unsignedInteger('registered_schools');
            $table->unsignedInteger('registered_students');
            $table->unsignedBigInteger('registration_fees_due_cents');
            $table->unsignedBigInteger('registration_fees_paid_cents');
            // Signed: overpayment (paid > due) is possible if a fee is lowered
            // after payments have settled.
            $table->bigInteger('registration_fees_outstanding_cents');
            $table->timestamps();

            $table->unique(['version_id', 'captured_on'], 'version_scorecard_snapshots_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_scorecard_snapshots');
    }
};
