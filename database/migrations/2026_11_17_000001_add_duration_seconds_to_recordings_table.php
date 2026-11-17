<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            // Captured from the judge's browser the first time the audio's
            // metadata loads on the Adjudicate page — see Adjudicate::recordDuration().
            $table->unsignedInteger('duration_seconds')->nullable()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            $table->dropColumn('duration_seconds');
        });
    }
};
