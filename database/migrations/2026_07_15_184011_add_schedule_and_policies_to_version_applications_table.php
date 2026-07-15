<?php

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
        Schema::table('version_applications', function (Blueprint $table) {
            $table->longText('schedule_body')->nullable()->after('teacher_principal_endorsement_body');
            $table->longText('policies_body')->nullable()->after('schedule_body');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('version_applications', function (Blueprint $table) {
            $table->dropColumn(['schedule_body', 'policies_body']);
        });
    }
};
