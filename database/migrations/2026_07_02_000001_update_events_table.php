<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'ends_at', 'is_open']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->string('short_name')->nullable()->after('name');
            $table->string('logo_url')->nullable()->after('short_name');
            $table->string('logo_alt')->nullable()->after('logo_url');
            $table->string('status')->default('sandbox')->after('logo_alt');
            $table->string('frequency')->default('annual')->after('status');
            $table->unsignedSmallInteger('audition_count')->default(1)->after('frequency');
            $table->unsignedSmallInteger('ensemble_count')->default(1)->after('audition_count');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['short_name', 'logo_url', 'logo_alt', 'status', 'frequency', 'audition_count', 'ensemble_count']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->date('starts_at')->after('name');
            $table->date('ends_at')->nullable()->after('starts_at');
            $table->boolean('is_open')->default(true)->after('ends_at');
        });
    }
};
