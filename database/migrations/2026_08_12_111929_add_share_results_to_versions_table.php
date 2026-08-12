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
            $table->boolean('share_results')->default(false)->after('pitch_file_visibility');
        });
    }

    public function down(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->dropColumn('share_results');
        });
    }
};
