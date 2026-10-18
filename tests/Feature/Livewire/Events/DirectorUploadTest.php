<?php

declare(strict_types=1);

use App\Enums\VersionInvitationStatus;
use App\Livewire\Events\DirectorUpload;
use App\Livewire\Events\VersionInvitations;
use App\Models\County;
use App\Models\Event;
use App\Models\Geostate;
use App\Models\Organization;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\SchoolTeacherSubject;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionCounty;
use App\Models\VersionInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A Version restricted to a fresh NJ county, plus an Event Manager for it.
 *
 * @return array{0: Version, 1: County, 2: User}
 */
function duSetup(): array
{
    $nj = Geostate::where('abbr', 'NJ')->firstOrFail();
    $county = County::factory()->create(['geostate_id' => $nj->id, 'name' => 'Duplicateless']);
    $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $version = Version::factory()->create(['event_id' => $event->id]);
    VersionCounty::create(['version_id' => $version->id, 'county_id' => $county->id]);

    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $version, 'Event Manager');

    return [$version, $county, $eventManager];
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function duRow(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Quentin',
        'last_name' => 'Zarnecki',
        'email' => 'qzarnecki@zarnecki-test.org',
        'school_name' => 'Zorblax Regional High School',
        'school_city' => 'Zorblax',
        'school_zip' => '99901',
        'school_county' => 'Duplicateless',
        'subjects' => 'band|chorus',
    ], $overrides);
}

/**
 * @param  list<array<string, string>>  $rows
 */
function duCsvFile(array $rows): UploadedFile
{
    $headers = array_keys(array_merge(...$rows));
    $lines = [implode(',', $headers)];

    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(fn (string $h): string => '"'.str_replace('"', '""', $row[$h] ?? '').'"', $headers));
    }

    return UploadedFile::fake()->createWithContent('directors.csv', implode("\n", $lines)."\n");
}

/**
 * @param  list<array<string, string>>  $rows
 */
function duPreview(Version $version, User $eventManager, array $rows): Testable
{
    return Livewire::actingAs($eventManager)
        ->test(DirectorUpload::class, ['version' => $version])
        ->set('csvFile', duCsvFile($rows))
        ->call('previewUpload')
        ->assertHasNoErrors();
}

function duSchool(County $county, string $name = 'Zorblax Regional High School', string $zip = '99901'): School
{
    return School::factory()->create(['name' => $name, 'zip_code' => $zip, 'county_id' => $county->id, 'geostate_id' => $county->geostate_id]);
}

test('a new director gets a user, teacher, school, verified link, subjects and an invitation', function () {
    [$version, $county, $eventManager] = duSetup();

    duPreview($version, $eventManager, [duRow(['honorific' => 'Dr.', 'pronoun' => 'they', 'cell_phone' => '(609) 555-0199', 'school_email' => 'QZ@zorblax.k12.nj.us'])])
        ->assertSet('preview.0.outcome', 'new')
        ->call('confirmUpload')
        ->assertSet('step', 'done')
        ->assertSet('result.invited', 1)
        ->assertSet('result.newUsers', 1)
        ->assertSet('result.newSchools', 1)
        ->assertDispatched('directors-uploaded');

    $user = User::where('email', 'qzarnecki@zarnecki-test.org')->firstOrFail();
    expect($user->name)->toBe('Dr. Quentin Zarnecki')
        ->and($user->cell_phone)->toBe('6095550199')
        ->and($user->pronoun?->personal)->toBe('they')
        ->and($user->hasRole('Teacher'))->toBeTrue();

    $teacher = $user->teacher;
    expect($teacher->onboarding_step)->toBe(3);

    $school = School::where('name', 'Zorblax Regional High School')->firstOrFail();
    expect($school->county_id)->toBe($county->id);

    $link = SchoolTeacher::where('school_id', $school->id)->where('teacher_id', $teacher->id)->firstOrFail();
    expect($link->is_active)->toBeTrue()
        ->and($link->verified_at)->not->toBeNull()
        ->and($link->school_email)->toBe('qz@zorblax.k12.nj.us')
        ->and(SchoolTeacherSubject::where('school_teacher_id', $link->id)->count())->toBe(2);

    $invitation = VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->firstOrFail();
    expect($invitation->getRawOriginal('status'))->toBe(VersionInvitationStatus::Invited->value)
        ->and($invitation->invited_by_user_id)->toBe($eventManager->id);
});

test('uploading the same file twice creates nothing new', function () {
    [$version, , $eventManager] = duSetup();
    $rows = [duRow(), duRow(['first_name' => 'Ramona', 'last_name' => 'Quill', 'email' => 'rquill@zarnecki-test.org'])];

    duPreview($version, $eventManager, $rows)->call('confirmUpload')->assertSet('result.invited', 2);

    $counts = [User::count(), Teacher::count(), School::count(), SchoolTeacher::count(), VersionInvitation::count()];

    duPreview($version, $eventManager, $rows)
        ->assertSet('preview.0.outcome', 'already_invited')
        ->assertSet('preview.1.outcome', 'already_invited')
        ->call('confirmUpload')
        ->assertSet('result.invited', 0)
        ->assertSet('result.alreadyInvited', 2);

    expect([User::count(), Teacher::count(), School::count(), SchoolTeacher::count(), VersionInvitation::count()])->toBe($counts);
});

test('two rows sharing a new school create it only once', function () {
    [$version, , $eventManager] = duSetup();

    duPreview($version, $eventManager, [duRow(), duRow(['first_name' => 'Ramona', 'last_name' => 'Quill', 'email' => 'rquill@zarnecki-test.org'])])
        ->call('confirmUpload')
        ->assertSet('result.invited', 2)
        ->assertSet('result.newSchools', 1);

    expect(School::where('name', 'Zorblax Regional High School')->count())->toBe(1);
});

test('the leading zero Excel drops from a zip is restored and matches the existing school case-insensitively', function () {
    [$version, $county, $eventManager] = duSetup();
    $school = duSchool($county, 'Zorblax Regional High School', '09901');

    duPreview($version, $eventManager, [duRow(['school_name' => 'zorblax regional high school', 'school_zip' => '9901'])])
        ->assertSet('preview.0.outcome', 'new')
        ->call('confirmUpload')
        ->assertSet('result.newSchools', 0);

    expect(School::where('zip_code', '09901')->count())->toBe(1)
        ->and(SchoolTeacher::where('school_id', $school->id)->count())->toBe(1);
});

test('an existing teacher matched by email gets the CSV school added without their details being overwritten', function () {
    [$version, $county, $eventManager] = duSetup();
    $user = User::factory()->create(['first_name' => 'Quinn', 'last_name' => 'Zarnecki', 'email' => 'qzarnecki@zarnecki-test.org']);
    $teacher = Teacher::factory()->create(['user_id' => $user->id]);
    $otherSchool = School::factory()->create();
    $teacher->schools()->attach($otherSchool->id, ['is_active' => true, 'verified_at' => now()]);
    $usersBefore = User::count();

    duPreview($version, $eventManager, [duRow(['email' => 'QZarnecki@Zarnecki-Test.org'])])
        ->assertSet('preview.0.outcome', 'existing')
        ->call('confirmUpload')
        ->assertSet('result.invited', 1)
        ->assertSet('result.newUsers', 0);

    expect(User::count())->toBe($usersBefore)
        ->and($user->fresh()->first_name)->toBe('Quinn')
        ->and($teacher->schools()->count())->toBe(2)
        ->and(VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->exists())->toBeTrue();
});

test('an inactive, unverified school link is reactivated and verified', function () {
    [$version, $county, $eventManager] = duSetup();
    $school = duSchool($county);
    $user = User::factory()->create(['email' => 'qzarnecki@zarnecki-test.org']);
    $teacher = Teacher::factory()->create(['user_id' => $user->id]);
    $teacher->schools()->attach($school->id, ['is_active' => false]);

    duPreview($version, $eventManager, [duRow()])->call('confirmUpload')->assertSet('result.invited', 1);

    $link = SchoolTeacher::where('school_id', $school->id)->where('teacher_id', $teacher->id)->firstOrFail();
    expect($link->is_active)->toBeTrue()->and($link->verified_at)->not->toBeNull();
});

test('the invited director appears on the Invitations roster as invited', function () {
    [$version, , $eventManager] = duSetup();

    duPreview($version, $eventManager, [duRow()])->call('confirmUpload');

    Livewire::actingAs($eventManager)
        ->test(VersionInvitations::class, ['version' => $version])
        ->assertSee('Quentin Zarnecki')
        ->assertViewHas('statusCounts', fn (array $counts): bool => $counts['invited'] === 1);
});

test('a near-match school is flagged, blocks confirm until resolved, and the chosen school is used', function () {
    [$version, $county, $eventManager] = duSetup();
    $existing = duSchool($county);
    $schoolsBefore = School::count();

    $component = duPreview($version, $eventManager, [duRow(['school_name' => 'Zorblax Regional HS'])])
        ->assertSet('preview.0.outcome', 'possible_duplicate')
        ->call('confirmUpload')
        ->assertHasErrors('resolutions');

    expect(VersionInvitation::where('version_id', $version->id)->count())->toBe(0);

    $component->set('resolutions.2.school', (string) $existing->id)
        ->call('confirmUpload')
        ->assertSet('result.invited', 1);

    expect(School::count())->toBe($schoolsBefore)
        ->and(SchoolTeacher::where('school_id', $existing->id)->count())->toBe(1);
});

test('choosing "create new" for a near-match school creates it', function () {
    [$version, $county, $eventManager] = duSetup();
    duSchool($county);

    duPreview($version, $eventManager, [duRow(['school_name' => 'Zorblax Regional HS'])])
        ->set('resolutions.2.school', 'new')
        ->call('confirmUpload')
        ->assertSet('result.newSchools', 1);

    expect(School::where('name', 'Zorblax Regional HS')->exists())->toBeTrue();
});

test('the same name at the same school with a different email is flagged as a possible duplicate', function () {
    [$version, $county, $eventManager] = duSetup();
    $school = duSchool($county);
    $existingUser = User::factory()->create(['first_name' => 'Quentin', 'last_name' => 'Zarnecki', 'email' => 'old-address@zarnecki-test.org']);
    $existingTeacher = Teacher::factory()->create(['user_id' => $existingUser->id]);
    $existingTeacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    $usersBefore = User::count();

    duPreview($version, $eventManager, [duRow()])
        ->assertSet('preview.0.outcome', 'possible_duplicate')
        ->assertSet('preview.0.personChoices.0.id', $existingTeacher->id)
        ->set('resolutions.2.person', (string) $existingTeacher->id)
        ->call('confirmUpload')
        ->assertSet('result.invited', 1);

    expect(User::count())->toBe($usersBefore)
        ->and(VersionInvitation::where('version_id', $version->id)->where('teacher_id', $existingTeacher->id)->exists())->toBeTrue();
});

test('choosing "create new director" for a flagged person creates a separate account', function () {
    [$version, $county, $eventManager] = duSetup();
    $school = duSchool($county);
    $existingTeacher = Teacher::factory()->create(['user_id' => User::factory()->create(['first_name' => 'Quentin', 'last_name' => 'Zarnecki'])->id]);
    $existingTeacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);

    duPreview($version, $eventManager, [duRow()])
        ->set('resolutions.2.person', 'new')
        ->call('confirmUpload')
        ->assertSet('result.newUsers', 1);

    expect(User::where('email', 'qzarnecki@zarnecki-test.org')->exists())->toBeTrue();
});

test('a cell phone owned by a teacher flags a possible duplicate', function () {
    [$version, , $eventManager] = duSetup();
    $phoneTeacher = Teacher::factory()->create(['user_id' => User::factory()->create(['cell_phone' => '6095550123'])->id]);

    duPreview($version, $eventManager, [duRow(['cell_phone' => '609-555-0123'])])
        ->assertSet('preview.0.outcome', 'possible_duplicate')
        ->assertSet('preview.0.personChoices.0.id', $phoneTeacher->id);
});

test('a cell phone owned by a non-teacher account is dropped, not duplicated', function () {
    [$version, , $eventManager] = duSetup();
    User::factory()->create(['cell_phone' => '6095550124']);

    duPreview($version, $eventManager, [duRow(['cell_phone' => '6095550124'])])
        ->assertSet('preview.0.outcome', 'new')
        ->call('confirmUpload')
        ->assertSet('result.invited', 1);

    expect(User::where('email', 'qzarnecki@zarnecki-test.org')->value('cell_phone'))->toBeNull();
});

test('an email belonging to a student account is rejected', function () {
    [$version, , $eventManager] = duSetup();
    $studentUser = User::factory()->create(['email' => 'qzarnecki@zarnecki-test.org']);
    Student::factory()->create(['user_id' => $studentUser->id]);
    $teachersBefore = Teacher::count();

    duPreview($version, $eventManager, [duRow()])
        ->assertSet('preview.0.outcome', 'error')
        ->call('confirmUpload')
        ->assertSet('result.skipped', 1);

    expect(Teacher::count())->toBe($teachersBefore);
});

test('a school outside the Version counties is rejected', function () {
    [$version, , $eventManager] = duSetup();
    $nj = Geostate::where('abbr', 'NJ')->firstOrFail();
    County::factory()->create(['geostate_id' => $nj->id, 'name' => 'Elsewhereton']);

    duPreview($version, $eventManager, [duRow(['school_county' => 'Elsewhereton County'])])
        ->assertSet('preview.0.outcome', 'error');
});

test('an unknown county and an unknown pronoun are row errors', function () {
    [$version, , $eventManager] = duSetup();

    duPreview($version, $eventManager, [duRow(['school_county' => 'Nowhere-at-all', 'pronoun' => 'zzz'])])
        ->assertSet('preview.0.outcome', 'error');
});

test('a duplicate email within the file is an error on the second row only', function () {
    [$version, , $eventManager] = duSetup();

    duPreview($version, $eventManager, [duRow(), duRow(['first_name' => 'Other'])])
        ->assertSet('preview.0.outcome', 'new')
        ->assertSet('preview.1.outcome', 'error')
        ->call('confirmUpload')
        ->assertSet('result.invited', 1)
        ->assertSet('result.skipped', 1);
});

test('a director with no subjects starts onboarding at role and subjects', function () {
    [$version, , $eventManager] = duSetup();

    duPreview($version, $eventManager, [duRow(['subjects' => ''])])->call('confirmUpload');

    expect(User::where('email', 'qzarnecki@zarnecki-test.org')->firstOrFail()->teacher->onboarding_step)->toBe(2);
});

test('a file missing required columns is rejected', function () {
    [$version, , $eventManager] = duSetup();

    Livewire::actingAs($eventManager)
        ->test(DirectorUpload::class, ['version' => $version])
        ->set('csvFile', UploadedFile::fake()->createWithContent('directors.csv', "first_name,last_name\nA,B\n"))
        ->call('previewUpload')
        ->assertHasErrors('csvFile')
        ->assertSet('step', 'upload');
});

test('the component and template download are forbidden to a non-manager', function () {
    [$version] = duSetup();
    $outsider = User::factory()->create();

    Livewire::actingAs($outsider)
        ->test(DirectorUpload::class, ['version' => $version])
        ->assertStatus(403);

    $this->actingAs($outsider)
        ->get(route('events.versions.invitations.director-template', $version))
        ->assertForbidden();
});

test('an Event Manager can download the template', function () {
    [$version, , $eventManager] = duSetup();

    $response = $this->actingAs($eventManager)
        ->get(route('events.versions.invitations.director-template', $version));

    $response->assertOk()->assertDownload('director-upload-template.csv');
});
