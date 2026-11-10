<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateStatus;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a Student (and its User) that a teacher's Remove has left as an
 * untouched shell: no teacher rosters it anywhere, nobody has ever signed in
 * as it, and it has no audition activity. Teacher-created duplicates (the
 * Nov 2026 "Ian Lee" case) otherwise linger with an active school_student
 * row and an auto-enrolled Eligible Candidate, invisible on every roster but
 * still turning up as possible matches and in Version eligibility.
 *
 * Every test here is deliberately conservative — anything that looks like a
 * real person used the account, or that any Version recorded work against
 * it, leaves the record alone (Remove then behaves exactly as before).
 */
class AbandonedStudentCleanupService
{
    /** Tables keyed on candidate_id whose rows mean real audition activity. */
    private const CANDIDATE_ACTIVITY_TABLES = [
        'recordings',
        'candidate_upload_files',
        'payment_allocations',
        'audition_results',
        'scores',
    ];

    public function isAbandoned(Student $student): bool
    {
        $user = User::find($student->user_id);

        if ($user === null || $user->teacher()->exists()) {
            return false;
        }

        if (DB::table('student_teacher')->where('student_id', $student->id)->exists()) {
            return false;
        }

        return $this->accountNeverUsed($user) && ! $this->hasAuditionActivity($student, $user);
    }

    /**
     * @return bool whether the student was deleted
     */
    public function deleteIfAbandoned(Student $student): bool
    {
        return DB::transaction(function () use ($student): bool {
            if (! $this->isAbandoned($student)) {
                return false;
            }

            $userId = $student->user_id;

            // Candidates cascade their status history; isAbandoned() has ruled
            // out every other child row worth keeping.
            DB::table('candidates')->where('student_id', $student->id)->delete();
            DB::table('school_student')->where('student_id', $student->id)->delete();
            DB::table('home_addresses')->where('student_id', $student->id)->delete();
            // Bypass the soft-delete scope: the FK isn't cascading, so trashed rows would block the delete.
            DB::table('emergency_contacts')->where('student_id', $student->id)->delete();
            $student->delete();

            $user = User::find($userId);
            $user?->pageVisits()->delete();
            $user?->phones()->delete();
            $user?->socialAccounts()->delete();
            $user?->delete();

            return true;
        });
    }

    /**
     * A teacher-created placeholder: no password, no verified email, and no
     * trace of anyone ever signing in (password, social or passkey).
     */
    private function accountNeverUsed(User $user): bool
    {
        if ($user->getRawOriginal('password') !== null || $user->email_verified_at !== null) {
            return false;
        }

        return ! DB::table('login_events')->where('user_id', $user->id)->exists()
            && ! DB::table('social_accounts')->where('user_id', $user->id)->exists()
            && ! DB::table('passkeys')->where('user_id', $user->id)->exists();
    }

    private function hasAuditionActivity(Student $student, User $user): bool
    {
        $candidates = DB::table('candidates')->where('student_id', $student->id);

        $beyondEligible = (clone $candidates)
            ->where(fn ($q) => $q
                ->where('status', '!=', CandidateStatus::Eligible->value)
                ->orWhereNotNull('application_certified_at')
                ->orWhereNotNull('application_candidate_signed_at')
                ->orWhereNotNull('application_parent_signed_at'))
            ->exists();

        if ($beyondEligible) {
            return true;
        }

        $candidateIds = (clone $candidates)->pluck('id');

        if ($candidateIds->isNotEmpty()) {
            foreach (self::CANDIDATE_ACTIVITY_TABLES as $table) {
                if (DB::table($table)->whereIn('candidate_id', $candidateIds)->exists()) {
                    return true;
                }
            }
        }

        return DB::table('scores')->where('student_id', $student->id)->exists()
            || DB::table('payment_transactions')->where('payer_student_id', $student->id)->exists()
            || DB::table('candidate_status_history')->where('user_id', $user->id)->exists()
            || DB::table('recordings')->where('uploaded_by', $user->id)->exists();
    }
}
