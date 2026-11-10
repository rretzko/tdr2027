<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per (candidate, FeeType) the Event Manager has waived in
        // full — the row's existence IS the waiver; deleting it un-waives.
        // See docs/plans/student-fee-waivers.md.
        Schema::create('candidate_fee_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            // App\Enums\FeeType: registration | participation | housing
            $table->string('fee_type');
            $table->text('reason');
            $table->foreignId('waived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['candidate_id', 'fee_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_fee_waivers');
    }
};
