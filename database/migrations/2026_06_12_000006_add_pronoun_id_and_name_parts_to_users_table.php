<?php

declare(strict_types=1);

use App\Models\Pronoun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignIdFor(Pronoun::class)->after('email')->default(1)->constrained();
            $table->string('honorific')->nullable()->after('name');
            $table->string('first_name')->after('honorific');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('last_name')->after('middle_name');
            $table->string('suffix_name')->nullable()->after('last_name');
            $table->boolean('email_unverifiable')->default(false)->after('email_verified_at');

            $table->index(['last_name', 'first_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_name', 'first_name']);
            $table->dropConstrainedForeignIdFor(Pronoun::class);
            $table->dropColumn([
                'honorific',
                'first_name',
                'middle_name',
                'last_name',
                'suffix_name',
                'email_unverifiable',
            ]);
        });
    }
};
