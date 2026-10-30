<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Candidate;
use App\Models\EmergencyContact;
use App\Models\HomeAddress;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\Recording;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MergeStudentsTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeStudent(string $firstName, string $lastName): Student
    {
        $student = Student::factory()->create();
        $student->user->update(['first_name' => $firstName, 'last_name' => $lastName]);

        return $student->fresh('user');
    }

    private function schoolStudentRow(Student $student, School $school, int $classOf = 2028): void
    {
        DB::table('school_student')->insert([
            'student_id' => $student->id,
            'school_id' => $school->id,
            'is_active' => true,
            'class_of' => $classOf,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function studentTeacherRow(Student $student, Teacher $teacher, School $school, string $subject = 'chorus'): void
    {
        DB::table('student_teacher')->insert([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'school_id' => $school->id,
            'subject' => $subject,
            'role' => 'primary',
            'is_active' => true,
            'claim_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Guard tests
    // -----------------------------------------------------------------------

    public function test_fails_when_winner_id_not_found(): void
    {
        $loser = $this->makeStudent('Duplicate', 'Xyzzyx');

        $this->artisan('students:merge', ['winner' => 99999, 'loser' => $loser->id, '--force' => true])
            ->assertFailed();
    }

    public function test_fails_when_loser_id_not_found(): void
    {
        $winner = $this->makeStudent('Canonical', 'Xyzzyx');

        $this->artisan('students:merge', ['winner' => $winner->id, 'loser' => 99999, '--force' => true])
            ->assertFailed();
    }

    public function test_fails_when_winner_and_loser_are_same_student(): void
    {
        $student = $this->makeStudent('Canonical', 'Xyzzyx');

        $this->artisan('students:merge', ['winner' => $student->id, 'loser' => $student->id, '--force' => true])
            ->assertFailed();
    }

    public function test_aborts_when_confirmation_declined(): void
    {
        $winner = $this->makeStudent('Canonical', 'Xyzzyx');
        $loser = $this->makeStudent('Duplicate', 'Xyzzyx');

        $this->artisan('students:merge', ['winner' => $winner->id, 'loser' => $loser->id])
            ->expectsConfirmation('Merge loser #'.$loser->id.' into winner #'.$winner->id.' and permanently delete the loser?', 'no')
            ->assertSuccessful();

        // loser should still exist
        $this->assertDatabaseHas('students', ['id' => $loser->id]);
    }

    // -----------------------------------------------------------------------
    // school_student transfer
    // -----------------------------------------------------------------------

    public function test_transfers_loser_school_student_rows_to_winner(): void
    {
        $winner = $this->makeStudent('Canonical', 'Xyzzyx');
        $loser = $this->makeStudent('Duplicate', 'Xyzzyx');
        $school = School::factory()->create();

        $this->schoolStudentRow($loser, $school);

        $this->artisan('students:merge', ['winner' => $winner->id, 'loser' => $loser->id, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('school_student', ['student_id' => $winner->id, 'school_id' => $school->id]);
        $this->assertDatabaseMissing('school_student', ['student_id' => $loser->id]);
    }

    public function test_skips_conflicting_school_student_row_when_winner_already_enrolled(): void
    {
        $winner = $this->makeStudent('Canonical', 'Xyzzyx');
        $loser = $this->makeStudent('Duplicate', 'Xyzzyx');
        $school = School::factory()->create();

        $this->schoolStudentRow($winner, $school, 2027);
        $this->schoolStudentRow($loser, $school, 2028);

        $this->artisan('students:merge', ['winner' => $winner->id, 'loser' => $loser->id, '--force' => true])
            ->assertSuccessful();

        // winner row kept with its original class_of; loser row deleted
        $this->assertDatabaseHas('school_student', ['student_id' => $winner->id, 'school_id' => $school->id, 'class_of' => 2027]);
        $this->assertDatabaseMissing('school_student', ['student_id' => $loser->id]);
    }

    // -----------------------------------------------------------------------
    // student_teacher transfer
    // -----------------------------------------------------------------------

    public function test_transfers_loser_student_teacher_rows_to_winner(): void
    {
        $winner = $this->makeStudent('Canonical', 'Xyzzyx');
        $loser = $this->makeStudent('Duplicate', 'Xyzzyx');
        $school = School::factory()->create();
        $teacher = Teacher::factory()->create();

        $this->studentTeacherRow($loser, $teacher, $school);

        $this->artisan('students:merge', ['winner' => $winner->id, 'loser' => $loser->id, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('student_teacher', ['student_id' => $winner->id, 'teacher_id' => $teacher->id]);
        $this->assertDatabaseMissing('student_teacher', ['student_id' => $loser->id]);
    }

    public function test_skips_duplicate_student_teacher_row_when_winner_already_has_same_combo(): void
    {
        $winner = $this->makeStudent('Canonical', 'Xyzzyx');
        $loser = $this->makeStudent('Duplicate', 'Xyzzyx');
        $school = School::factory()->create();
        $teacher = Teacher::factory()->create();

        $this->studentTeacherRow($winner, $teacher, $school, 'chorus');
        $this->studentTeacherRow($loser, $teacher, $school, 'chorus');

        $this->artisan('students:merge', ['winner' => $winner->id, 'loser' => $loser->id, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(
            1,
            DB::table('student_teacher')
                ->where('student_id', $winner->id)
                ->where('teacher_id', $teacher->id)
                ->where('school_id', $school->id)
                ->where('subject', 'chorus')
                ->count()
        );
        $this->assertDatabaseMissing('student_teacher', ['student_id' => $loser->id]);
    }

    // -----------------------------------------------------------------------
    // emergency_contacts
    // -----------------------------------------------------------------------

    public function test_reassigns_emergency_contacts_to_winner(): void
    {
        $winner = $this->makeStudent('Canonical', 'Xyzzyx');
        $loser = $this->makeStudent('Duplicate', 'Xyzzyx');

        EmergencyContact::factory()->create(['student_id' => $loser->id]);

        $this->artisan('students:merge', ['winner' => $winner->id, 'loser' => $loser->id, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(1, EmergencyContact::where('student_id', $winner->id)->count());
        $this->assertSame(0, EmergencyContact::where('student_id', $loser->id)->count());
    }

    // -----------------------------------------------------------------------
    // home_address
    // -----------------------------------------------------------------------

    public function test_transfers_home_address_to_winner_when_winner_has_none(): void
    {
        $winner = $this->makeStudent('Canonical', 'Xyzzyx');
        $loser = $this->makeStudent('Duplicate', 'Xyzzyx');

        HomeAddress::factory()->create(['student_id' => $loser->id]);

        $this->artisan('students:merge', ['winner' => $winner->id, 'loser' => $loser->id, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('home_addresses', ['student_id' => $winner->id]);
        $this->assertDatabaseMissing('home_addresses', ['student_id' => $loser->id]);
    }

    public function test_discards_loser_home_address_when_winner_already_has_one(): void
    {
        $winner = $this->makeStudent('Canonical', 'Xyzzyx');
        $loser = $this->makeStudent('Duplicate', 'Xyzzyx');

        HomeAddress::factory()->create(['student_id' => $winner->id, 'address1' => '1 Winner Lane']);
        HomeAddress::factory()->create(['student_id' => $loser->id, 'address1' => '2 Loser Ave']);

        $this->artisan('students:merge', ['winner' => $winner->id, 'loser' => $loser->id, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('home_addresses', ['student_id' => $winner->id, 'address1' => '1 Winner Lane']);
        $this->assertDatabaseMissing('home_addresses', ['student_id' => $loser->id]);
    }

    // -----------------------------------------------------------------------
    // candidates / scores / payments
    // -----------------------------------------------------------------------

    public function test_reassigns_loser_candidates_to_winner_with_their_child_rows(): void
    {
        $winner = $this->makeStudent('Canonical', 'Xyzzyx');
        $loser = $this->makeStudent('Duplicate', 'Xyzzyx');
        $candidate = Candidate::factory()->create(['student_id' => $loser->id]);

        // CandidateObserver::created() writes the initial status-history row
        $this->assertSame(1, DB::table('candidate_status_history')->where('candidate_id', $candidate->id)->count());

        $this->artisan('students:merge', ['winner' => $winner->id, 'loser' => $loser->id, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('candidates', ['id' => $candidate->id, 'student_id' => $winner->id]);
        $this->assertSame(1, DB::table('candidate_status_history')->where('candidate_id', $candidate->id)->count());
    }

    public function test_candidate_recordings_and_payments_survive_the_merge(): void
    {
        $winner = $this->makeStudent('Canonical', 'Xyzzyx');
        $loser = $this->makeStudent('Duplicate', 'Xyzzyx');
        $candidate = Candidate::factory()->create(['student_id' => $loser->id]);
        $teacherUserId = Teacher::factory()->create()->user_id;

        $recordingId = DB::table('recordings')->insertGetId([
            'version_id' => $candidate->version_id,
            'candidate_id' => $candidate->id,
            'file_type' => 'solo',
            'uploaded_by' => $teacherUserId,
            'url' => "candidateUploads/{$candidate->id}/solo.m4a",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $allocation = PaymentAllocation::factory()->create(['candidate_id' => $candidate->id]);

        $this->artisan('students:merge', ['winner' => $winner->id, 'loser' => $loser->id, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('candidates', ['id' => $candidate->id, 'student_id' => $winner->id]);
        $this->assertDatabaseHas('recordings', ['id' => $recordingId, 'candidate_id' => $candidate->id]);
        $this->assertDatabaseHas('payment_allocations', ['id' => $allocation->id, 'candidate_id' => $candidate->id]);
    }

    public function test_activity_by_the_loser_user_is_reassigned_to_the_winner_user(): void
    {
        $winner = $this->makeStudent('Canonical', 'Xyzzyx');
        $loser = $this->makeStudent('Duplicate', 'Xyzzyx');
        $candidate = Candidate::factory()->create(['student_id' => $loser->id]);

        // The student uploaded their own recording (cascades on User delete) and
        // changed a status (RESTRICT on User delete — would abort the merge).
        $recordingId = DB::table('recordings')->insertGetId([
            'version_id' => $candidate->version_id,
            'candidate_id' => $candidate->id,
            'file_type' => 'solo',
            'uploaded_by' => $loser->user_id,
            'url' => "candidateUploads/{$candidate->id}/solo.m4a",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $historyId = DB::table('candidate_status_history')->insertGetId([
            'candidate_id' => $candidate->id,
            'from_status' => 'eligible',
            'to_status' => 'registered',
            'user_id' => $loser->user_id,
            'created_at' => now(),
        ]);

        $this->artisan('students:merge', ['winner' => $winner->id, 'loser' => $loser->id, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $loser->user_id]);
        $this->assertDatabaseHas('recordings', ['id' => $recordingId, 'uploaded_by' => $winner->user_id]);
        $this->assertDatabaseHas('candidate_status_history', ['id' => $historyId, 'user_id' => $winner->user_id]);
    }

    public function test_fails_without_prompting_when_loser_candidate_has_adjudication_data_in_a_shared_version(): void
    {
        $winner = $this->makeStudent('Canonical', 'Xyzzyx');
        $loser = $this->makeStudent('Duplicate', 'Xyzzyx');
        $version = Version::factory()->create(['name' => 'Region II Chorus 2027']);
        $winnerCandidate = Candidate::factory()->create(['student_id' => $winner->id, 'version_id' => $version->id]);
        $loserCandidate = Candidate::factory()->create(['student_id' => $loser->id, 'version_id' => $version->id]);
        Recording::create([
            'version_id' => $version->id,
            'candidate_id' => $loserCandidate->id,
            'file_type' => 'Solo',
            'uploaded_by' => User::factory()->create()->id,
            'url' => 'recordings/solo.mp3',
        ]);

        // No --force: the refusal must come before the confirmation prompt
        $this->artisan('students:merge', ['winner' => $winner->id, 'loser' => $loser->id])
            ->expectsOutputToContain('Region II Chorus 2027')
            ->assertFailed();

        $this->assertDatabaseHas('students', ['id' => $loser->id]);
        $this->assertDatabaseHas('candidates', ['id' => $winnerCandidate->id, 'student_id' => $winner->id]);
        $this->assertDatabaseHas('candidates', ['id' => $loserCandidate->id, 'student_id' => $loser->id]);
    }

    public function test_same_version_pair_keeps_winner_candidate_and_moves_loser_allocations_onto_it(): void
    {
        $winner = $this->makeStudent('Canonical', 'Xyzzyx');
        $loser = $this->makeStudent('Duplicate', 'Xyzzyx');
        $version = Version::factory()->create();
        $winnerCandidate = Candidate::factory()->create(['student_id' => $winner->id, 'version_id' => $version->id]);
        $loserCandidate = Candidate::factory()->create(['student_id' => $loser->id, 'version_id' => $version->id]);
        $allocation = PaymentAllocation::factory()->create(['candidate_id' => $loserCandidate->id]);

        $this->artisan('students:merge', ['winner' => $winner->id, 'loser' => $loser->id, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('candidates', ['id' => $loserCandidate->id]);
        $this->assertDatabaseHas('payment_allocations', ['id' => $allocation->id, 'candidate_id' => $winnerCandidate->id]);
    }

    public function test_reassigns_payment_transactions_paid_by_loser(): void
    {
        $winner = $this->makeStudent('Canonical', 'Xyzzyx');
        $loser = $this->makeStudent('Duplicate', 'Xyzzyx');
        $transaction = PaymentTransaction::factory()->create(['payer_student_id' => $loser->id]);

        $this->artisan('students:merge', ['winner' => $winner->id, 'loser' => $loser->id, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('payment_transactions', ['id' => $transaction->id, 'payer_student_id' => $winner->id]);
    }

    // -----------------------------------------------------------------------
    // Student profile fields
    // -----------------------------------------------------------------------

    public function test_fills_winner_blank_profile_fields_from_loser_without_overwriting(): void
    {
        $winner = $this->makeStudent('Canonical', 'Xyzzyx');
        $loser = $this->makeStudent('Duplicate', 'Xyzzyx');
        $voicePart = VoicePart::factory()->create();

        // Raw writes: the date cast would store '2012-07-31 00:00:00' in SQLite
        DB::table('students')->where('id', $winner->id)->update(['birthday' => null, 'height' => 60, 'voice_part_id' => null]);
        DB::table('students')->where('id', $loser->id)->update(['birthday' => '2012-07-31', 'height' => 72, 'voice_part_id' => $voicePart->id]);

        $this->artisan('students:merge', ['winner' => $winner->id, 'loser' => $loser->id, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('students', [
            'id' => $winner->id,
            'birthday' => '2012-07-31',
            'voice_part_id' => $voicePart->id,
            'height' => 60,
        ]);
    }

    // -----------------------------------------------------------------------
    // Loser deletion
    // -----------------------------------------------------------------------

    public function test_deletes_loser_student_and_user(): void
    {
        $winner = $this->makeStudent('Canonical', 'Xyzzyx');
        $loser = $this->makeStudent('Duplicate', 'Xyzzyx');
        $loserUserId = $loser->user_id;

        $this->artisan('students:merge', ['winner' => $winner->id, 'loser' => $loser->id, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('students', ['id' => $loser->id]);
        $this->assertDatabaseMissing('users', ['id' => $loserUserId]);
    }

    public function test_keeps_loser_user_when_they_also_have_a_teacher_record(): void
    {
        $winner = $this->makeStudent('Canonical', 'Xyzzyx');
        $loser = $this->makeStudent('Duplicate', 'Xyzzyx');
        $loserUser = $loser->user;

        // attach a Teacher record to the loser's User so deletion must be skipped
        Teacher::factory()->create(['user_id' => $loserUser->id]);

        $this->artisan('students:merge', ['winner' => $winner->id, 'loser' => $loser->id, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('students', ['id' => $loser->id]);
        $this->assertDatabaseHas('users', ['id' => $loserUser->id]);
    }

    // -----------------------------------------------------------------------
    // Winner integrity
    // -----------------------------------------------------------------------

    public function test_winner_student_and_user_are_untouched(): void
    {
        $winner = $this->makeStudent('Canonical', 'Xyzzyx');
        $loser = $this->makeStudent('Duplicate', 'Xyzzyx');

        $this->artisan('students:merge', ['winner' => $winner->id, 'loser' => $loser->id, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('students', ['id' => $winner->id, 'user_id' => $winner->user_id]);
        $this->assertDatabaseHas('users', ['id' => $winner->user_id]);
    }
}
