<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\VersionInvitationStatus;
use App\Livewire\Events\Reports\Downloads;
use App\Livewire\Events\Reports\Index;
use App\Models\Candidate;
use App\Models\EmergencyContact;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/**
 * An invited teacher with one active school — the "owned" set the
 * Downloads exports are built from.
 *
 * @return array{teacher: Teacher, school: School}
 */
function downloadsInvitedTeacher(Version $version): array
{
    $school = School::factory()->create();
    $teacher = Teacher::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now(), 'school_email' => 'teacher@school.test']);

    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    return ['teacher' => $teacher, 'school' => $school];
}

function downloadsStudent(Teacher $teacher, School $school, bool $schoolActive = true): Student
{
    $student = Student::factory()->create();
    $student->schools()->attach($school->id, ['is_active' => $schoolActive, 'class_of' => now()->year + 1]);
    $student->teachers()->attach($teacher->id, ['school_id' => $school->id, 'subject' => 'chorus', 'is_active' => $schoolActive]);

    return $student;
}

function downloadsCsv(Version $version, string $topic): string
{
    $response = get(route('events.versions.reports.downloads.export', ['version' => $version, 'topic' => $topic]));
    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');

    return $response->streamedContent();
}

test('the Downloads card shows for an Event Manager', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(Index::class, ['version' => $version])
        ->assertSee('Downloads')
        ->assertSee("Download your event's data.");
});

test('the Downloads card is hidden from a Registration Manager', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Registration Manager');

    Livewire::actingAs($user)
        ->test(Index::class, ['version' => $version])
        ->assertDontSee("Download your event's data.");
});

test('the Downloads page is forbidden to Registration and Co-Registration Managers', function (string $role) {
    $user = User::factory()->create();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, $role);

    Livewire::actingAs($user)
        ->test(Downloads::class, ['version' => $version])
        ->assertStatus(403);

    actingAs($user);
    get(route('events.versions.reports.downloads.export', ['version' => $version, 'topic' => 'students']))
        ->assertForbidden();
})->with(['Registration Manager', 'Co-Registration Manager']);

test('the Downloads page lists every topic for an Event Manager', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(Downloads::class, ['version' => $version])
        ->assertOk()
        ->assertSee(['Event / Version', 'Schools', 'Teachers', 'Students', 'Candidates']);
});

test('an unknown topic is a 404', function () {
    actingAs(makeFounder());

    get('/events/versions/'.Version::factory()->create()->id.'/reports/downloads/passwords.csv')->assertNotFound();
});

test('event-version export is a key/value list including related settings', function () {
    actingAs(makeFounder());
    $version = Version::factory()->create();
    $version->fees()->create(['registration' => 2500, 'on_site_registration' => 0, 'participation' => 1000, 'epayment_surcharge' => 0, 'housing' => 0]);

    $csv = downloadsCsv($version, 'event-version');
    $pairs = collect(explode("\n", trim($csv)))->skip(1)
        ->map(fn (string $line): array => str_getcsv($line))
        ->mapWithKeys(fn (array $pair): array => [$pair[0] => $pair[1] ?? '']);

    expect($csv)->toStartWith("key,value\n")
        ->and($pairs['event.name'])->toBe($version->event->name)
        ->and($pairs['version.name'])->toBe($version->name)
        ->and($pairs['fee.registration'])->toBe('25.00')
        ->and($pairs['fee.participation'])->toBe('10.00')
        ->and($pairs->has('version.id'))->toBeFalse()
        ->and(str_contains($csv, 'created_at'))->toBeFalse();
});

test('teachers export has one row per teacher per active school and no credentials', function () {
    actingAs(makeFounder());
    $version = Version::factory()->create();
    ['teacher' => $teacher, 'school' => $school] = downloadsInvitedTeacher($version);
    $secondSchool = School::factory()->create();
    $teacher->schools()->attach($secondSchool->id, ['is_active' => true, 'verified_at' => now()]);
    $inactiveSchool = School::factory()->create();
    $teacher->schools()->attach($inactiveSchool->id, ['is_active' => false]);

    $uninvited = Teacher::factory()->create();

    $csv = downloadsCsv($version, 'teachers');
    $lines = array_values(array_filter(explode("\n", $csv)));

    expect($lines)->toHaveCount(3) // header + two active schools
        ->and($csv)->toContain($school->name)
        ->toContain($secondSchool->name)
        ->toContain('teacher@school.test')
        ->toContain('Invited');

    foreach (['password', 'two_factor', 'remember_token', ',id,'] as $forbidden) {
        expect(str_contains($lines[0], $forbidden))->toBeFalse();
    }

    expect(str_contains($csv, $inactiveSchool->name))->toBeFalse()
        ->and(str_contains($csv, $uninvited->user->last_name))->toBeFalse();
});

test('schools export lists invited teachers\' active schools', function () {
    actingAs(makeFounder());
    $version = Version::factory()->create();
    ['teacher' => $teacher, 'school' => $school] = downloadsInvitedTeacher($version);
    $otherSchool = School::factory()->create();

    $csv = downloadsCsv($version, 'schools');

    expect($csv)->toContain($school->name)
        ->toContain($teacher->user->name)
        ->and(str_contains($csv, $otherSchool->name))->toBeFalse();
});

test('students export is limited to active schools and numbers guardians', function () {
    actingAs(makeFounder());
    $version = Version::factory()->create();
    ['teacher' => $teacher, 'school' => $school] = downloadsInvitedTeacher($version);

    $active = downloadsStudent($teacher, $school);
    EmergencyContact::factory()->create(['student_id' => $active->id, 'name' => 'First Guardian']);
    EmergencyContact::factory()->create(['student_id' => $active->id, 'name' => 'Second Guardian']);
    $active->homeAddress()->create(['address1' => '12 Main St', 'city' => 'Trenton', 'geo_state' => 'NJ', 'zip_code' => '08601']);

    $inactive = downloadsStudent($teacher, School::factory()->create(), schoolActive: false);

    $csv = downloadsCsv($version, 'students');
    $header = (string) strtok($csv, "\n");

    expect($header)->toContain('guardian_1.name')
        ->toContain('guardian_2.name')
        ->toContain('address.address1')
        ->toContain('class_of')
        ->toContain('grade')
        ->and(str_contains($header, 'password'))->toBeFalse()
        ->and(str_contains($csv, $inactive->user->last_name))->toBeFalse()
        ->and($csv)->toContain($active->user->last_name)
        ->toContain('First Guardian')
        ->toContain('Second Guardian')
        ->toContain('12 Main St')
        ->toContain($teacher->user->name)
        ->toContain($school->name);
});

test('candidates export translates ids and includes ref, fees and paid', function () {
    actingAs(makeFounder());
    $version = Version::factory()->create();
    $version->fees()->create(['registration' => 2500, 'on_site_registration' => 0, 'participation' => 0, 'epayment_surcharge' => 0, 'housing' => 0]);
    ['teacher' => $teacher, 'school' => $school] = downloadsInvitedTeacher($version);
    $student = downloadsStudent($teacher, $school);

    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'student_id' => $student->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Registered,
    ]);
    $candidate->refresh();

    $csv = downloadsCsv($version, 'candidates');
    $header = (string) strtok($csv, "\n");

    expect($header)->toStartWith('ref,')
        ->toContain('fee.due')
        ->toContain('fee.paid')
        ->toContain('score.total')
        ->toContain('result.accepted_ensemble')
        ->and(str_contains($header, 'voice_part_id'))->toBeFalse()
        ->and(str_contains($header, 'student_id'))->toBeFalse()
        ->and($csv)->toContain((string) $candidate->ref)
        ->toContain($school->name)
        ->toContain($candidate->voicePart->name)
        ->toContain('Registered')
        ->toContain('25.00');
});
