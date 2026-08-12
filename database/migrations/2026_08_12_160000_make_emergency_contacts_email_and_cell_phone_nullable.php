<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether email/cell_phone are actually required is conditional per
     * Version (versions.emergency_contact_cell / emergency_contact_email —
     * see HasCandidateChecklist and CandidateDetail::saveEmergencyContact()),
     * so the column itself can't enforce NOT NULL.
     */
    public function up(): void
    {
        Schema::table('emergency_contacts', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('cell_phone', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('emergency_contacts', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->string('cell_phone', 20)->nullable(false)->change();
        });
    }
};
