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
        Schema::table('teacher_supervisors', function (Blueprint $table) {
            $table->string('supervisor_name')->nullable()->change();
            $table->string('supervisor_email')->nullable()->change();
            $table->string('supervisory_cell_phone')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teacher_supervisors', function (Blueprint $table) {
            $table->string('supervisor_name')->change();
            $table->string('supervisor_email')->change();
            $table->string('supervisory_cell_phone')->change();
        });
    }
};
