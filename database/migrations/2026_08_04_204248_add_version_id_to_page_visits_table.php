<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_visits', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('page_visits', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'route_name']);

            $table->foreignId('version_id')->nullable()->after('route_name')->constrained()->nullOnDelete();

            $table->unique(['user_id', 'route_name', 'version_id']);
        });
    }

    public function down(): void
    {
        Schema::table('page_visits', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'route_name', 'version_id']);
            $table->dropConstrainedForeignId('version_id');

            $table->unique(['user_id', 'route_name']);
        });

        Schema::table('page_visits', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });
    }
};
