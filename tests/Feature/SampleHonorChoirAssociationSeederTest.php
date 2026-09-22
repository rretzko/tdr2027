<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\EventStatus;
use App\Models\AuditionResult;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Recording;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Services\VersionRoleService;
use Database\Seeders\SampleHonorChoirAssociationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

// TestCase::$seed = true, so RefreshDatabase already runs the default
// DatabaseSeeder (lookup tables everywhere, plus real CSV-backed data on a
// dev machine that has database/seeders/data/*.csv locally). Assertions
// below are scoped to the fictional "Sample ..." records this seeder
// creates, never to global model counts, so they hold whether or not local
// CSV data happens to be present.
uses(RefreshDatabase::class);

test('SampleHonorChoirAssociationSeeder builds a complete fictional demo dataset', function () {
    Artisan::call('db:seed', ['--class' => SampleHonorChoirAssociationSeeder::class]);

    $organization = Organization::where('name', 'Sample County Honor Choir Association')->first();
    expect($organization)->not->toBeNull();

    $event = Event::where('organization_id', $organization->id)->first();
    expect($event)->not->toBeNull();
    expect($event->status)->toBe(EventStatus::Active);

    $schoolIds = School::whereIn('name', [
        'Sample North High School', 'Sample Valley High School', 'Sample Ridge Middle School',
    ])->pluck('id');
    expect($schoolIds)->toHaveCount(3);

    $teacherIds = SchoolTeacher::whereIn('school_id', $schoolIds)->pluck('teacher_id')->unique();
    expect($teacherIds)->toHaveCount(3);

    expect(Student::whereIn('home_school_id', $schoolIds)->count())->toBe(24); // 3 schools x 8 students

    expect(Ensemble::where('event_id', $event->id)->count())->toBe(3);

    $closedVersion = Version::where('event_id', $event->id)->where('name', 'Fall 2025 Sample Cycle')->first();
    $activeVersion = Version::where('event_id', $event->id)->where('name', 'Fall 2026 Sample Cycle')->first();
    expect($closedVersion)->not->toBeNull();
    expect($activeVersion)->not->toBeNull();
    expect($closedVersion->status)->toBe(EventStatus::Closed);
    expect($activeVersion->status)->toBe(EventStatus::Active);

    // 6 candidates per school in each cycle = 18 per cycle.
    expect(Candidate::where('version_id', $closedVersion->id)->count())->toBe(18);
    expect(Candidate::where('version_id', $activeVersion->id)->count())->toBe(18);

    $accepted = Candidate::where('version_id', $closedVersion->id)->where('status', CandidateStatus::Accepted)->get();
    expect($accepted)->toHaveCount(12); // 4 of 6 per school x 3 schools
    expect($accepted->every(fn (Candidate $c) => $c->accepted_ensemble_id !== null))->toBeTrue();

    $notAccepted = Candidate::where('version_id', $closedVersion->id)->where('status', CandidateStatus::NotAccepted)->count();
    expect($notAccepted)->toBe(3);

    $noShow = Candidate::where('version_id', $closedVersion->id)->where('status', CandidateStatus::NoShow)->count();
    expect($noShow)->toBe(3);

    // AuditionResult rows exist only for resolved (accepted/not-accepted) candidates.
    expect(AuditionResult::whereIn('candidate_id', Candidate::where('version_id', $closedVersion->id)->pluck('id'))->count())
        ->toBe(12 + 3);

    // Recordings exist for every closed-cycle candidate except no-shows.
    expect(Recording::where('version_id', $closedVersion->id)->count())->toBe(18 - 3);

    // No results/recordings/ensemble assignments on the still-open cycle.
    expect(AuditionResult::whereIn('candidate_id', Candidate::where('version_id', $activeVersion->id)->pluck('id'))->count())->toBe(0);
    expect(Candidate::where('version_id', $activeVersion->id)->whereNotNull('accepted_ensemble_id')->count())->toBe(0);

    // Demo logins exist and can authenticate with the documented password.
    $eventManagerUser = User::where('email', 'demo.eventmanager@sample-honorchoir.example')->first();
    $teacherUser = User::where('email', 'demo.teacher@sample-honorchoir.example')->first();
    expect($eventManagerUser)->not->toBeNull();
    expect($teacherUser)->not->toBeNull();
    expect(Hash::check('password', $eventManagerUser->password))->toBeTrue();
    expect(Hash::check('password', $teacherUser->password))->toBeTrue();

    $versionRoles = app(VersionRoleService::class);
    $hasClosedRole = $versionRoles->withVersion($closedVersion, fn () => $eventManagerUser->hasRole('Event Manager'));
    $hasActiveRole = $versionRoles->withVersion($activeVersion, fn () => $eventManagerUser->hasRole('Event Manager'));
    expect($hasClosedRole)->toBeTrue();
    expect($hasActiveRole)->toBeTrue();

    expect($teacherUser->hasRole('Teacher'))->toBeTrue();

    // The demo teacher's school link is active and verified, satisfying the
    // gate that controls whether a teacher can see their students.
    $demoTeacher = Teacher::where('user_id', $teacherUser->id)->first();
    $schoolTeacher = SchoolTeacher::where('teacher_id', $demoTeacher->id)->first();
    expect($schoolTeacher->is_active)->toBeTrue();
    expect($schoolTeacher->verified_at)->not->toBeNull();
});

test('SampleHonorChoirAssociationSeeder is idempotent when run twice', function () {
    Artisan::call('db:seed', ['--class' => SampleHonorChoirAssociationSeeder::class]);
    Artisan::call('db:seed', ['--class' => SampleHonorChoirAssociationSeeder::class]);

    $schoolIds = School::whereIn('name', [
        'Sample North High School', 'Sample Valley High School', 'Sample Ridge Middle School',
    ])->pluck('id');

    expect(Organization::where('name', 'Sample County Honor Choir Association')->count())->toBe(1);
    expect($schoolIds)->toHaveCount(3);
    expect(Student::whereIn('home_school_id', $schoolIds)->count())->toBe(24);
    expect(SchoolTeacher::whereIn('school_id', $schoolIds)->pluck('teacher_id')->unique())->toHaveCount(3);
    expect(User::where('email', 'demo.eventmanager@sample-honorchoir.example')->count())->toBe(1);
    expect(User::where('email', 'demo.teacher@sample-honorchoir.example')->count())->toBe(1);
});
