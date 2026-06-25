<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('route_name');
            $table->string('label');
            $table->unsignedInteger('visit_count')->default(1);
            $table->timestamp('last_visited_at');
            $table->timestamps();

            $table->unique(['user_id', 'route_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_visits');
    }
};
