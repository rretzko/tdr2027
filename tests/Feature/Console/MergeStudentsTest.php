<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\EmergencyContact;
use App\Models\HomeAddress;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
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
