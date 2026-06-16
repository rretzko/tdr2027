<?php

declare(strict_types=1);

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
        Schema::table('school_teacher', function (Blueprint $table) {
            $table->string('role')->nullable()->after('teacher_id');
            $table->string('replacing_teacher_name')->nullable()->after('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('school_teacher', function (Blueprint $table) {
            $table->dropColumn(['role', 'replacing_teacher_name']);
        });
    }
};
