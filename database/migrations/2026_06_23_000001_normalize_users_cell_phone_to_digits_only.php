<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Historical data was imported with formatted phone numbers (e.g.
     * "(201) 755-4083"), which silently broke the digits-only matching
     * used by social login (App\Livewire\Auth\SocialPhoneCheck) and
     * caused duplicate users to be created. A handful of rows also have
     * a trailing extension (e.g. "ext 1", "x3") left over from a landline
     * import; the users table has no extension field, so only the first
     * 10 digits are kept for those rows.
     */
    public function up(): void
    {
        DB::table('users')->whereNotNull('cell_phone')->select('id', 'cell_phone')
            ->orderBy('id')
            ->each(function (object $user): void {
                $digits = substr((string) preg_replace('/\D/', '', $user->cell_phone), 0, 10);
                $digits = $digits === '' ? null : $digits;

                if ($digits !== $user->cell_phone) {
                    DB::table('users')->where('id', $user->id)->update(['cell_phone' => $digits]);
                }
            });
    }

    public function down(): void
    {
        // Original formatting is not recoverable.
    }
};
