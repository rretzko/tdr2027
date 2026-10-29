<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RepairStudentTeacherSchoolTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function enroll(Student $student, School $school, bool $isActive = true): void
    {
        DB::table('school_student')->insert([
            'student_id' => $student->id,
            'school_id' => $school->id,
            'is_active' => $isActive,
            'class_of' => 2031,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function affiliate(Teacher $teacher, School $school, bool $verified = true): void
    {
        DB::table('school_teacher')->insert([
            'school_id' => $school->id,
            'teacher_id' => $teacher->id,
            'is_active' => true,
            'verified_at' => $verified ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function roster(Student $student, Teacher $teacher, School $school): int
    {
        return DB::table('student_teacher')->insertGetId([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'school_id' => $school->id,
            'subject' => 'chorus',
            'role' => 'coteacher',
            'is_active' => true,
            'claim_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function schoolIdOf(int $rowId): int
    {
        return (int) DB::table('student_teacher')->where('id', $rowId)->value('school_id');
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    public function test_dry_run_reports_but_does_not_write(): void
    {
        [$highSchool, $middleSchool] = School::factory()->count(2)->create();
        $teacher = Teacher::factory()->create();
        $student = Student::factory()->create();

        $this->affiliate($teacher, $highSchool);
        $this->affiliate($teacher, $middleSchool);
        $this->enroll($student, $middleSchool);
        $rowId = $this->roster($student, $teacher, $highSchool);

        $this->artisan('students:repair-roster-school')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame($highSchool->id, $this->schoolIdOf($rowId));
    }

    public function test_apply_moves_row_to_the_students_enrolled_school(): void
    {
        [$highSchool, $middleSchool] = School::factory()->count(2)->create();
        $teacher = Teacher::factory()->create();
        $student = Student::factory()->create();

        $this->affiliate($teacher, $highSchool);
        $this->affiliate($teacher, $middleSchool);
        $this->enroll($student, $middleSchool);
        $rowId = $this->roster($student, $teacher, $highSchool);

        $this->artisan('students:repair-roster-school', ['--apply' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertSame($middleSchool->id, $this->schoolIdOf($rowId));
    }

    public function test_leaves_correctly_matched_rows_alone(): void
    {
        $school = School::factory()->create();
        $teacher = Teacher::factory()->create();
        $student = Student::factory()->create();

        $this->affiliate($teacher, $school);
        $this->enroll($student, $school);
        $rowId = $this->roster($student, $teacher, $school);
        $updatedAt = DB::table('student_teacher')->where('id', $rowId)->value('updated_at');

        $this->travel(1)->minutes();

        $this->artisan('students:repair-roster-school', ['--apply' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertSame($school->id, $this->schoolIdOf($rowId));
        $this->assertSame($updatedAt, DB::table('student_teacher')->where('id', $rowId)->value('updated_at'));
    }

    public function test_skips_when_teacher_is_not_verified_at_the_enrolled_school(): void
    {
        [$highSchool, $middleSchool] = School::factory()->count(2)->create();
        $teacher = Teacher::factory()->create();
        $student = Student::factory()->create();

        $this->affiliate($teacher, $highSchool);
        $this->affiliate($teacher, $middleSchool, verified: false);
        $this->enroll($student, $middleSchool);
        $rowId = $this->roster($student, $teacher, $highSchool);

        $this->artisan('students:repair-roster-school', ['--apply' => true, '--force' => true])
            ->expectsOutputToContain('not verified')
            ->assertSuccessful();

        $this->assertSame($highSchool->id, $this->schoolIdOf($rowId));
    }

    public function test_skips_student_with_no_enrollment(): void
    {
        $school = School::factory()->create();
        $teacher = Teacher::factory()->create();
        $student = Student::factory()->create();

        $this->affiliate($teacher, $school);
        $rowId = $this->roster($student, $teacher, $school);

        $this->artisan('students:repair-roster-school', ['--apply' => true, '--force' => true])
            ->expectsOutputToContain('no school_student row')
            ->assertSuccessful();

        $this->assertSame($school->id, $this->schoolIdOf($rowId));
    }

    public function test_skips_when_a_matching_row_already_exists_at_the_target_school(): void
    {
        [$highSchool, $middleSchool] = School::factory()->count(2)->create();
        $teacher = Teacher::factory()->create();
        $student = Student::factory()->create();

        $this->affiliate($teacher, $highSchool);
        $this->affiliate($teacher, $middleSchool);
        $this->enroll($student, $middleSchool);
        $this->roster($student, $teacher, $middleSchool);
        $rowId = $this->roster($student, $teacher, $highSchool);

        $this->artisan('students:repair-roster-school', ['--apply' => true, '--force' => true])
            ->expectsOutputToContain('Duplicate')
            ->assertSuccessful();

        $this->assertSame($highSchool->id, $this->schoolIdOf($rowId));
    }

    public function test_prefers_the_active_enrollment_when_teacher_is_verified_at_several(): void
    {
        [$oldSchool, $newSchool, $wrongSchool] = School::factory()->count(3)->create();
        $teacher = Teacher::factory()->create();
        $student = Student::factory()->create();

        $this->affiliate($teacher, $oldSchool);
        $this->affiliate($teacher, $newSchool);
        $this->affiliate($teacher, $wrongSchool);
        $this->enroll($student, $oldSchool, isActive: false);
        $this->enroll($student, $newSchool);
        $rowId = $this->roster($student, $teacher, $wrongSchool);

        $this->artisan('students:repair-roster-school', ['--apply' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertSame($newSchool->id, $this->schoolIdOf($rowId));
    }
}
