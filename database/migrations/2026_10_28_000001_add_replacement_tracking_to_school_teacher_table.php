<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * replaced_teacher_id: the teacher this link replaces, picked from a list
     * instead of matched by free-typed name (docs/plans/replaced-teacher-transfer-fix.md).
     *
     * replacement_transferred_at: when ReplacedTeacherStudentTransfer last ran
     * for this link, so SchoolTeacherObserver runs it once per replacement rather
     * than on every re-verification. Backfilled to verified_at for already-verified
     * replacement rows so deploying this changes no existing rosters — those are
     * repaired deliberately (Phase 2), not by a side effect of the next save.
     */
    public function up(): void
    {
        // Adding the FK rebuilds the table, which strict mode refuses while any
        // row holds a zero date (school_teacher 471 had verified_at
        // 0000-00-00 00:00:00 from a direct edit). The app already read it as
        // verified, so created_at keeps that meaning with a real value.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("UPDATE school_teacher SET verified_at = created_at WHERE CAST(verified_at AS CHAR) LIKE '0000-00-00%'");
        }

        Schema::table('school_teacher', function (Blueprint $table) {
            $table->foreignId('replaced_teacher_id')->nullable()->after('replacing_teacher_name')
                ->constrained('teachers')->nullOnDelete();
            $table->timestamp('replacement_transferred_at')->nullable()->after('verified_at');
        });

        DB::table('school_teacher')
            ->whereNotNull('replacing_teacher_name')
            ->where('replacing_teacher_name', '!=', '')
            ->whereNotNull('verified_at')
            ->update(['replacement_transferred_at' => DB::raw('verified_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('school_teacher', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaced_teacher_id');
            $table->dropColumn('replacement_transferred_at');
        });
    }
};
