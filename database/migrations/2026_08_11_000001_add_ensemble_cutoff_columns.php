<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->foreignId('accepted_ensemble_id')->nullable()->after('voice_part_id')->constrained('ensembles')->nullOnDelete();
        });

        Schema::table('versions', function (Blueprint $table) {
            $table->string('cutoff_strategy')->nullable()->after('score_order');
        });

        Schema::table('ensembles', function (Blueprint $table) {
            $table->string('color')->nullable()->after('abbreviation');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accepted_ensemble_id');
        });

        Schema::table('versions', function (Blueprint $table) {
            $table->dropColumn('cutoff_strategy');
        });

        Schema::table('ensembles', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
