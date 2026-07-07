<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->unsignedInteger('max_upper_voice_registrants')->nullable()->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->unsignedInteger('max_upper_voice_registrants')->nullable()->default(null)->change();
        });
    }
};
