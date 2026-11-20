<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\VersionDateType;
use App\Enums\VersionInvitationStatus;
use App\Models\Candidate;
use App\Models\Event;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionInvitation;
use App\Services\SchoolRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    // CandidateObserver writes candidate_status_history rows keyed by Auth::id().
    actingAs(User::factory()->create());
});

function retentionTeacher(School $school, string $name): Teacher
{
    [$first, $last] = explode(' ', $name, 2);
    $user = User::factory()->create(['first_name' => $first, 'middle_name' => null, 'last_name' => $last, 'suffix_name' => null, 'honorific' => null]);
    $teacher = Teacher::factory()->create(['user_id' => $user->id]);
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);

    return $teacher;
}

function retentionStudents(Version $version, School $school, Teacher $teacher, int $count, CandidateStatus $status): void
{
    Candidate::factory()->count($count)->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'status' => $status,
    ]);
}

function retentionInvite(Version $version, Teacher $teacher): void
{
    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * Last year's and this year's Versions of one event.
 *
 * @return array{prior: Version, current: Version}
 */
function retentionVersions(): array
{
    $event = Event::factory()->create();

    return [
        'prior' => Version::factory()->create(['event_id' => $event->id, 'senior_class_of' => 2026, 'name' => 'Honor Choir 2026']),
        'current' => Version::factory()->create(['event_id' => $event->id, 'senior_class_of' => 2027]),
    ];
}

test('there is nothing to compare without an earlier Version of the event', function () {
    $version = Version::factory()->create(['senior_class_of' => 2027]);

    expect(app(SchoolRetentionService::class)->forVersion($version))->toBeNull();
});

test('schools are retained, not back, or new, and the student change is split between them', function () {
    ['prior' => $prior, 'current' => $current] = retentionVersions();
    [$kept, $shrunk, $gone, $fresh] = School::factory()->count(4)->create()->all();
    $keptTeacher = retentionTeacher($kept, 'Kept Teacher');
    $shrunkTeacher = retentionTeacher($shrunk, 'Shrunk Teacher');
    $goneTeacher = retentionTeacher($gone, 'Gone Teacher');
    $freshTeacher = retentionTeacher($fresh, 'Fresh Teacher');

    // Last year's results states all count as participating.
    retentionStudents($prior, $kept, $keptTeacher, 2, CandidateStatus::Accepted);
    retentionStudents($prior, $shrunk, $shrunkTeacher, 3, CandidateStatus::NotAccepted);
    retentionStudents($prior, $shrunk, $shrunkTeacher, 2, CandidateStatus::NoShow);
    retentionStudents($prior, $gone, $goneTeacher, 4, CandidateStatus::Accepted);
    // Never registered last year: not a participating school.
    retentionStudents($prior, $fresh, $freshTeacher, 1, CandidateStatus::Eligible);

    retentionStudents($current, $kept, $keptTeacher, 3, CandidateStatus::Registered);
    retentionStudents($current, $shrunk, $shrunkTeacher, 2, CandidateStatus::Registered);
    retentionStudents($current, $shrunk, $shrunkTeacher, 1, CandidateStatus::Pending);
    retentionStudents($current, $shrunk, $shrunkTeacher, 2, CandidateStatus::Eligible);
    retentionStudents($current, $fresh, $freshTeacher, 1, CandidateStatus::Registered);

    $retention = app(SchoolRetentionService::class)->forVersion($current);

    expect($retention['prior']->is($prior))->toBeTrue()
        ->and($retention['schools'])->toBe(['prior' => 3, 'retained' => 2, 'retainedPercent' => 67, 'notBack' => 1, 'new' => 1])
        ->and($retention['students'])->toBe(['prior' => 11, 'current' => 6, 'retainedChange' => -2, 'notBackLost' => -4, 'newGained' => 1])
        ->and($retention['fewer'])->toBe([
            ['name' => $shrunk->name, 'priorStudents' => 5, 'currentStudents' => 2, 'change' => -3, 'unregistered' => 3],
        ])
        ->and($retention['teacherChanged'])->toBe([])
        ->and(array_column($retention['notBack'], 'name'))->toBe([$gone->name]);
});

test('a school back under a different teacher is retained and listed as a teacher change', function () {
    ['prior' => $prior, 'current' => $current] = retentionVersions();
    $school = School::factory()->create();
    $retired = retentionTeacher($school, 'Retired Teacher');
    $successor = retentionTeacher($school, 'Successor Teacher');

    retentionStudents($prior, $school, $retired, 4, CandidateStatus::Accepted);
    retentionStudents($current, $school, $successor, 6, CandidateStatus::Registered);

    $retention = app(SchoolRetentionService::class)->forVersion($current);

    expect($retention['schools']['retained'])->toBe(1)
        ->and($retention['schools']['notBack'])->toBe(0)
        ->and($retention['teacherChanged'])->toBe([
            ['name' => $school->name, 'priorStudents' => 4, 'currentStudents' => 6, 'was' => ['Retired Teacher'], 'now' => ['Successor Teacher']],
        ]);
});

test('a school not back shows who is invited from it now, or that no one is', function () {
    ['prior' => $prior, 'current' => $current] = retentionVersions();
    [$orphaned, $stalled] = School::factory()->count(2)->create()->all();
    $leftTeacher = retentionTeacher($orphaned, 'Left Teacher');
    $stalledTeacher = retentionTeacher($stalled, 'Stalled Teacher');

    retentionStudents($prior, $orphaned, $leftTeacher, 5, CandidateStatus::Accepted);
    retentionStudents($prior, $stalled, $stalledTeacher, 3, CandidateStatus::Accepted);

    retentionInvite($current, $stalledTeacher);
    retentionStudents($current, $stalled, $stalledTeacher, 4, CandidateStatus::Eligible);
    retentionStudents($current, $stalled, $stalledTeacher, 1, CandidateStatus::Pending);

    $notBack = app(SchoolRetentionService::class)->forVersion($current)['notBack'];

    expect($notBack)->toBe([
        ['name' => $orphaned->name, 'priorStudents' => 5, 'priorTeachers' => ['Left Teacher'], 'invited' => []],
        ['name' => $stalled->name, 'priorStudents' => 3, 'priorTeachers' => ['Stalled Teacher'], 'invited' => [
            ['name' => 'Stalled Teacher', 'email' => $stalledTeacher->user->email, 'eligible' => 4, 'pending' => 1],
        ]],
    ]);
});

test('teachers are new to the event without earlier participation, and new to TDR without any earlier-year students', function () {
    ['prior' => $prior, 'current' => $current] = retentionVersions();
    $otherEventLastYear = Version::factory()->create(['senior_class_of' => 2026]);
    $school = School::factory()->create();
    $returning = retentionTeacher($school, 'Returning Teacher');
    $fromAnotherEvent = retentionTeacher($school, 'Elsewhere Teacher');
    $brandNew = retentionTeacher($school, 'Brand New Teacher');

    retentionStudents($prior, $school, $returning, 1, CandidateStatus::Accepted);
    retentionStudents($otherEventLastYear, $school, $fromAnotherEvent, 1, CandidateStatus::Accepted);

    retentionStudents($current, $school, $returning, 1, CandidateStatus::Registered);
    retentionStudents($current, $school, $fromAnotherEvent, 2, CandidateStatus::Registered);
    retentionStudents($current, $school, $brandNew, 3, CandidateStatus::Registered);

    expect(app(SchoolRetentionService::class)->forVersion($current)['teachers'])
        ->toBe(['current' => 3, 'newToEvent' => 2, 'newToEventStudents' => 5, 'newToTdr' => 1]);
});

test('schools are only "not back" once the Postmark Deadline has passed', function () {
    ['prior' => $prior, 'current' => $current] = retentionVersions();
    $school = School::factory()->create();
    retentionStudents($prior, $school, retentionTeacher($school, 'Some Teacher'), 1, CandidateStatus::Accepted);
    VersionDate::create(['version_id' => $current->id, 'date_type' => VersionDateType::PostmarkDeadline, 'start_at' => '2026-12-01 05:00:00']);

    $service = app(SchoolRetentionService::class);

    expect($service->forVersion($current, Carbon::parse('2026-11-20'))['final'])->toBeFalse()
        ->and($service->forVersion($current, Carbon::parse('2026-12-02'))['final'])->toBeTrue();
});

test('the Registration tab shows the retention panel', function () {
    ['prior' => $prior, 'current' => $current] = retentionVersions();
    [$gone, $changed] = School::factory()->count(2)->create()->all();
    retentionStudents($prior, $gone, retentionTeacher($gone, 'Gone Teacher'), 4, CandidateStatus::Accepted);
    retentionStudents($prior, $changed, retentionTeacher($changed, 'Old Teacher'), 3, CandidateStatus::Accepted);
    retentionStudents($current, $changed, retentionTeacher($changed, 'New Teacher'), 2, CandidateStatus::Registered);

    $html = view('livewire.events.version-scorecard-retention', [
        'retention' => app(SchoolRetentionService::class)->forVersion($current),
    ])->render();

    expect($html)->toContain('School retention')
        ->toContain('Compared with Honor Choir 2026')
        ->toContain('Not back yet')
        ->toContain('No invited teacher')
        ->toContain($gone->name)
        ->toContain('Retained with a teacher change')
        ->toContain('Old Teacher')
        ->toContain('New Teacher');
});
