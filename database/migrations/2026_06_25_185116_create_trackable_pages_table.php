<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trackable_pages', function (Blueprint $table) {
            $table->id();
            $table->string('route_name')->unique();
            $table->string('label');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('trackable_pages')->insert([
            ['route_name' => 'dashboard',          'label' => 'Dashboard',         'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['route_name' => 'schools.index',      'label' => 'Schools',           'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['route_name' => 'organizations.index', 'label' => 'Organizations',     'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['route_name' => 'students.index',     'label' => 'Students',          'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['route_name' => 'events.index',       'label' => 'Events',            'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['route_name' => 'settings.profile',   'label' => 'Profile Settings',  'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['route_name' => 'settings.password',  'label' => 'Password Settings', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('trackable_pages');
    }
};
