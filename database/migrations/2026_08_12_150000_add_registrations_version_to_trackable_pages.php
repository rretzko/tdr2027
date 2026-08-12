<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('trackable_pages')->insert([
            'route_name' => 'registrations.version',
            'label' => 'Registration Dashboard',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('trackable_pages')->where('route_name', 'registrations.version')->delete();
    }
};
