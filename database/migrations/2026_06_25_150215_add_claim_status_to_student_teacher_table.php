<?php

declare(strict_types=1);

use App\Enums\ClaimStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('student_teacher', function (Blueprint $table) {
            $table->string('claim_status')->default(ClaimStatus::Approved->value)->after('role');
            // Holds the requested grade (as class_of) while a cross-org claim is
            // pending — the school_student enrollment isn't created until the
            // claim is approved, so there's nowhere else to keep it until then.
            $table->unsignedSmallInteger('pending_class_of')->nullable()->after('claim_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_teacher', function (Blueprint $table) {
            $table->dropColumn(['claim_status', 'pending_class_of']);
        });
    }
};
