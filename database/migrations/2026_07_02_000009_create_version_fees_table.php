<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->unsignedInteger('registration')->default(2000);
            $table->unsignedInteger('on_site_registration')->default(0);
            $table->unsignedInteger('participation')->default(0);
            $table->unsignedInteger('epayment_surcharge')->default(0);
            $table->unsignedInteger('housing')->default(0);
            $table->timestamps();

            $table->unique('version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_fees');
    }
};
