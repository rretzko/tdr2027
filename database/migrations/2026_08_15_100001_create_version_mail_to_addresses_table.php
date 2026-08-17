<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_mail_to_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('recipient_name');
            $table->string('organization_line')->nullable();
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('city');
            $table->foreignId('geostate_id')->constrained('geostates');
            $table->string('zip');
            $table->timestamps();

            // One mailing address per Registration/Co-Registration Manager per Version.
            $table->unique(['version_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_mail_to_addresses');
    }
};
