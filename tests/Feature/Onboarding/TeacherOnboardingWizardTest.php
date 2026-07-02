<?php

declare(strict_types=1);

use App\Enums\EventInvitationStatus;
use App\Enums\SchoolType;
use App\Enums\TeacherRole;
use App\Livewire\Onboarding\TeacherOnboardingWizard;
use App\Models\County;
use App\Models\Event;
use App\Models\EventInvitationRequest;
use App\Models\Geostate;
use App\Models\Organization;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\SchoolTeacherSubject;
use App\Models\Pivots\StudentTeacher;
use App\Models\Pivots\TeacherSupervisor;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeTeacherUser(): User
{
    $user = User::factory()->create();
    Teacher::factory()->create(['user_id' => $user->id]);

    return $user;
}

test('a teacher with incomplete onboarding is redirected to the wizard from the dashboard', function () {
    $user = makeTeacherUser();

    actingAs($user)->get(route('dashboard'))->assertRedirectToRoute('teacher.onboarding');
});

test('a teacher who already completed onboarding can reach the dashboard', function () {
    $user = makeTeacherUser();
    $user->teacher->update(['onboarding_completed_at' => now()]);

    actingAs($user)->get(route('dashboard'))->assertOk();
});

test('a teacher who already completed onboarding is bounced away from the wizard', function () {
    $user = makeTeacherUser();
    $user->teacher->update(['onboarding_completed_at' => now()]);

    Livewire::actingAs($user)
        ->test(TeacherOnboardingWizard::class)
        ->assertRedirect(route('dashboard'));
});

test('step 1 suggests an existing school instead of creating a duplicate', function () {
    $user = makeTeacherUser();
    $geostate = Geostate::factory()->create();
    $county = County::factory()->create(['geostate_id' => $geostate->id]);
    $school = School::factory()->create([
        'name' => 'Central High School',
        'geostate_id' => $geostate->id,
        'county_id' => $county->id,
        'zip_code' => '08901',
    ]);

    Livewire::actingAs($user)
        ->test(TeacherOnboardingWizard::class)
        ->set('geostate_id', (string) $geostate->id)
        ->set('zip_code', '08901')
        ->set('school_search', 'Central High Schol') // typo
        ->assertSee('Central High School')
        ->call('selectSchool', $school->id)
        ->assertSet('step', 2);

    expect(School::where('name', 'Central High School')->count())->toBe(1);
    expect(SchoolTeacher::where('school_id', $school->id)->where('teacher_id', $user->teacher->id)->where('is_active', true)->exists())->toBeTrue();
});

test('step 1 suggests a school from a short partial name search', function () {
    $user = makeTeacherUser();
    $geostate = Geostate::factory()->create();
    $county = County::factory()->create(['geostate_id' => $geostate->id]);
    // Zip/name deliberately unusual (not a real NJ zip) so it can't collide with the
    // name+zip unique constraint against real schools.csv data seeded locally.
    $school = School::factory()->create([
        'name' => 'Quixmoor Ridge Academy',
        'geostate_id' => $geostate->id,
        'county_id' => $county->id,
        'zip_code' => '00001',
    ]);

    Livewire::actingAs($user)
        ->test(TeacherOnboardingWizard::class)
        ->set('geostate_id', (string) $geostate->id)
        ->set('zip_code', '00001')
        ->set('school_search', 'Ridge') // short partial name, not a typo of the full name
        ->assertSee('Quixmoor Ridge Academy')
        ->call('selectSchool', $school->id)
        ->assertSet('step', 2);
});

test('step 1 lists schools at a zip code before any name is typed', function () {
    $user = makeTeacherUser();
    $geostate = Geostate::factory()->create();
    $county = County::factory()->create(['geostate_id' => $geostate->id]);
    $school = School::factory()->create([
        'name' => 'Quixmoor Ridge Academy',
        'geostate_id' => $geostate->id,
        'county_id' => $county->id,
        'zip_code' => '00001',
    ]);

    Livewire::actingAs($user)
        ->test(TeacherOnboardingWizard::class)
        ->set('geostate_id', (string) $geostate->id)
        ->set('zip_code', '00001')
        ->assertSee('Quixmoor Ridge Academy')
        ->call('selectSchool', $school->id)
        ->assertSet('step', 2);
});

test('step 1 creates a new school when none matches', function () {
    $user = makeTeacherUser();
    $geostate = Geostate::factory()->create();
    $county = County::factory()->create(['geostate_id' => $geostate->id]);

    Livewire::actingAs($user)
        ->test(TeacherOnboardingWizard::class)
        ->set('geostate_id', (string) $geostate->id)
        ->set('school_search', 'Brand New Studio')
        ->set('creatingNewSchool', true)
        ->set('new_school_name', 'Brand New Studio')
        ->set('new_school_type', 'studio')
        ->set('new_school_city', 'Newtown')
        ->set('new_school_zip_code', '08901')
        ->set('new_school_county_id', (string) $county->id)
        ->call('createSchool')
        ->assertSet('step', 2);

    $school = School::where('name', 'Brand New Studio')->first();
    expect($school)->not->toBeNull();
    expect($school->type)->toBe(SchoolType::Studio);
    expect(SchoolTeacher::where('school_id', $school->id)->where('teacher_id', $user->teacher->id)->exists())->toBeTrue();
});

test('step 2 persists role, replacing-teacher name, and subjects', function () {
    $user = makeTeacherUser();
    $school = School::factory()->create();
    SchoolTeacher::create(['school_id' => $school->id, 'teacher_id' => $user->teacher->id, 'is_active' => true]);
    $user->teacher->update(['onboarding_step' => 2]);

    Livewire::actingAs($user)
        ->test(TeacherOnboardingWizard::class)
        ->set('role', 'coteacher')
        ->set('isReplacingTeacher', true)
        ->set('replacing_teacher_name', 'Pat Smith')
        ->set('subjects', ['band', 'orchestra'])
        ->call('saveRoleAndSubjects')
        ->assertSet('step', 3);

    $pivot = SchoolTeacher::where('teacher_id', $user->teacher->id)->first();

    expect($pivot->role)->toBe(TeacherRole::Coteacher);
    expect($pivot->replacing_teacher_name)->toBe('Pat Smith');
    expect(SchoolTeacherSubject::where('school_teacher_id', $pivot->id)->pluck('subject')->map(fn ($s) => $s->value)->sort()->values()->all())
        ->toBe(['band', 'orchestra']);
});

test('step 3 claims an existing student and creates a new placeholder student', function () {
    $user = makeTeacherUser();
    $school = School::factory()->create();
    SchoolTeacher::create(['school_id' => $school->id, 'teacher_id' => $user->teacher->id, 'is_active' => true]);
    $user->teacher->update(['onboarding_step' => 3]);

    $otherStudentUser = User::factory()->create();
    $existingStudent = Student::factory()->create(['user_id' => $otherStudentUser->id]);
    $school->students()->attach($existingStudent->id, ['is_active' => true, 'class_of' => 2027]);

    Livewire::actingAs($user)
        ->test(TeacherOnboardingWizard::class)
        ->set('subjects', ['band'])
        ->set('claimedStudentIds', [$existingStudent->id])
        ->call('addNewStudentRow')
        ->set('newStudents.0.first_name', 'Alex')
        ->set('newStudents.0.last_name', 'Taylor')
        ->set('newStudents.0.class_of', '2029')
        ->call('saveStudents')
        ->assertSet('step', 4);

    expect(StudentTeacher::where('student_id', $existingStudent->id)->where('teacher_id', $user->teacher->id)->exists())->toBeTrue();

    $newUser = User::where('first_name', 'Alex')->where('last_name', 'Taylor')->first();
    expect($newUser)->not->toBeNull();
    expect($newUser->email_unverifiable)->toBeTrue();

    $newStudent = Student::where('user_id', $newUser->id)->first();
    expect($newStudent->schools()->where('schools.id', $school->id)->wherePivot('class_of', 2029)->exists())->toBeTrue();
    expect(StudentTeacher::where('student_id', $newStudent->id)->where('teacher_id', $user->teacher->id)->exists())->toBeTrue();
});

test('step 3 shows a visible error and stays put when a new student is missing class of', function () {
    $user = makeTeacherUser();
    $school = School::factory()->create();
    SchoolTeacher::create(['school_id' => $school->id, 'teacher_id' => $user->teacher->id, 'is_active' => true]);
    $user->teacher->update(['onboarding_step' => 3]);

    Livewire::actingAs($user)
        ->test(TeacherOnboardingWizard::class)
        ->set('subjects', ['band'])
        ->call('addNewStudentRow')
        ->set('newStudents.0.first_name', 'Alex')
        ->set('newStudents.0.last_name', 'Taylor')
        // class_of intentionally left blank
        ->call('saveStudents')
        ->assertSet('step', 3)
        ->assertHasErrors('newStudents.0.class_of')
        ->assertSee('Choose a class of year.');

    expect(User::where('first_name', 'Alex')->where('last_name', 'Taylor')->exists())->toBeFalse();
});

test('step 3 rehydrates role and subjects from the database when the wizard remounts', function () {
    $user = makeTeacherUser();
    $school = School::factory()->create();
    $pivot = SchoolTeacher::create([
        'school_id' => $school->id,
        'teacher_id' => $user->teacher->id,
        'is_active' => true,
        'role' => TeacherRole::Coteacher->value,
        'replacing_teacher_name' => 'Pat Smith',
    ]);
    SchoolTeacherSubject::create(['school_teacher_id' => $pivot->id, 'subject' => 'band']);
    SchoolTeacherSubject::create(['school_teacher_id' => $pivot->id, 'subject' => 'chorus']);
    $user->teacher->update(['onboarding_step' => 3]);

    // A fresh mount (no manual ->set() calls) simulates the component remounting
    // after a page reload — everything must come back from the database, not memory.
    $component = Livewire::actingAs($user)->test(TeacherOnboardingWizard::class);

    $component->assertSet('role', 'coteacher')
        ->assertSet('isReplacingTeacher', true)
        ->assertSet('replacing_teacher_name', 'Pat Smith');

    expect($component->get('subjects'))->toEqualCanonicalizing(['band', 'chorus']);

    $component->call('addNewStudentRow')
        ->set('newStudents.0.first_name', 'Alex')
        ->set('newStudents.0.last_name', 'Taylor')
        ->set('newStudents.0.class_of', '2029')
        ->set('newStudents.0.subject', 'chorus')
        ->call('saveStudents')
        ->assertSet('step', 4)
        ->assertHasNoErrors();
});

test('step 4 lists organizations sorted by name with children indented under their parent', function () {
    $user = makeTeacherUser();
    $user->teacher->update(['onboarding_step' => 4]);

    $parent = Organization::factory()->create(['name' => 'New Jersey Music Educators Association']);
    $child = Organization::factory()->create([
        'name' => 'Central Jersey Music Educators Association',
        'parent_id' => $parent->id,
    ]);
    $standalone = Organization::factory()->create(['name' => 'Zzz Standalone Association']);

    $html = Livewire::actingAs($user)->test(TeacherOnboardingWizard::class)->html();

    $parentPos = strpos($html, $parent->name);
    $childPos = strpos($html, $child->name);
    $standalonePos = strpos($html, $standalone->name);

    expect($parentPos)->not->toBeFalse();
    expect($childPos)->not->toBeFalse();
    expect($standalonePos)->not->toBeFalse();
    // Parent appears before its child, and the unrelated org sorts after both
    // since "New Jersey..." < "Zzz Standalone..." alphabetically among top-level orgs.
    expect($parentPos)->toBeLessThan($childPos);
    expect($childPos)->toBeLessThan($standalonePos);

    // The child row is indented (depth 1); the parent/standalone rows are not.
    expect($html)->toContain('margin-left: 1.5rem');
});

test('step 4 can be completed with zero organizations selected', function () {
    $user = makeTeacherUser();
    $user->teacher->update(['onboarding_step' => 4]);

    Livewire::actingAs($user)
        ->test(TeacherOnboardingWizard::class)
        ->call('saveOrganizations')
        ->assertSet('step', 5);

    expect(TeacherSupervisor::count())->toBe(0);
});

test('step 4 links an organization with supervisor contact info', function () {
    $user = makeTeacherUser();
    $organization = Organization::factory()->create();
    $user->teacher->update(['onboarding_step' => 4]);

    Livewire::actingAs($user)
        ->test(TeacherOnboardingWizard::class)
        ->set('selectedOrganizationIds', [$organization->id])
        ->set("supervisorName.{$organization->id}", 'Jamie Lee')
        ->set("supervisorEmail.{$organization->id}", 'jamie@example.com')
        ->set("supervisorCellPhone.{$organization->id}", '5559876543')
        ->call('saveOrganizations')
        ->assertSet('step', 5);

    expect(TeacherSupervisor::where('organization_id', $organization->id)->where('teacher_id', $user->teacher->id)->exists())->toBeTrue();
});

test('step 4 links an organization without any supervisor contact info', function () {
    $user = makeTeacherUser();
    $organization = Organization::factory()->create();
    $user->teacher->update(['onboarding_step' => 4]);

    Livewire::actingAs($user)
        ->test(TeacherOnboardingWizard::class)
        ->set('selectedOrganizationIds', [$organization->id])
        ->call('saveOrganizations')
        ->assertSet('step', 5)
        ->assertHasNoErrors();

    $supervisor = TeacherSupervisor::where('organization_id', $organization->id)->where('teacher_id', $user->teacher->id)->first();

    expect($supervisor)->not->toBeNull();
    expect($supervisor->supervisor_name)->toBeNull();
    expect($supervisor->supervisor_email)->toBeNull();
    expect($supervisor->supervisory_cell_phone)->toBeNull();
});

test('step 5 can be completed with zero open events', function () {
    $user = makeTeacherUser();
    $user->teacher->update(['onboarding_step' => 5]);

    Livewire::actingAs($user)
        ->test(TeacherOnboardingWizard::class)
        ->assertSee('No open events')
        ->call('requestEventInvitations')
        ->assertSet('step', 6);

    expect(EventInvitationRequest::count())->toBe(0);
});

test('step 5 requests an invitation to an open event from a linked organization', function () {
    $user = makeTeacherUser();
    $organization = Organization::factory()->create();
    TeacherSupervisor::create([
        'organization_id' => $organization->id,
        'teacher_id' => $user->teacher->id,
        'supervisor_name' => 'Jamie Lee',
        'supervisor_email' => 'jamie@example.com',
        'supervisory_cell_phone' => '5559876543',
    ]);
    $event = Event::factory()->active()->create(['organization_id' => $organization->id]);
    $user->teacher->update(['onboarding_step' => 5]);

    Livewire::actingAs($user)
        ->test(TeacherOnboardingWizard::class)
        ->set('selectedEventIds', [$event->id])
        ->call('requestEventInvitations')
        ->assertSet('step', 6);

    $request = EventInvitationRequest::where('event_id', $event->id)->where('teacher_id', $user->teacher->id)->first();
    expect($request)->not->toBeNull();
    expect($request->status)->toBe(EventInvitationStatus::Pending);
});

test('step 6 finishing marks onboarding complete and unlocks the dashboard', function () {
    $user = makeTeacherUser();
    $user->teacher->update(['onboarding_step' => 6]);

    Livewire::actingAs($user)
        ->test(TeacherOnboardingWizard::class)
        ->call('finish')
        ->assertRedirect(route('dashboard'));

    expect($user->teacher->refresh()->onboarding_completed_at)->not->toBeNull();

    actingAs($user)->get(route('dashboard'))->assertOk();
});

test('the wizard resumes at the saved step after navigating away', function () {
    $user = makeTeacherUser();
    $user->teacher->update(['onboarding_step' => 4]);

    Livewire::actingAs($user)
        ->test(TeacherOnboardingWizard::class)
        ->assertSet('step', 4);
});

test('step 1 defaults the state to New Jersey', function () {
    $user = makeTeacherUser();
    $newJersey = Geostate::where('name', 'New Jersey')->first();

    Livewire::actingAs($user)
        ->test(TeacherOnboardingWizard::class)
        ->assertSet('geostate_id', (string) $newJersey->id);
});
