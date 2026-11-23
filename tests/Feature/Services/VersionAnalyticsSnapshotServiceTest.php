<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Jobs\CaptureVersionAnalyticsSnapshotJob;
use App\Models\Candidate;
use App\Models\Event;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionAnalyticsSnapshot;
use App\Models\VoicePart;
use App\Services\VersionAnalyticsSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

test('capture counts every registrant state, not just Registered, and lists stranded pending candidates by teacher', function () {
    $version = Version::factory()->create(['event_id' => Event::factory()->create()->id, 'status' => 'closed', 'cutoff_strategy' => 'grade_segmented_ensembles']);
    $teacher = Teacher::factory()->create(['user_id' => User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Director'])->id]);

    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'status' => CandidateStatus::Accepted]);
    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'status' => CandidateStatus::NotAccepted]);
    Candidate::factory()->registered()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    Candidate::factory()->count(2)->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'status' => CandidateStatus::Pending]);
    Candidate::factory()->create(['version_id' => $version->id, 'status' => CandidateStatus::Withdrew]);

    $snapshot = app(VersionAnalyticsSnapshotService::class)->capture($version);

    expect($snapshot->schema_version)->toBe(VersionAnalyticsSnapshotService::SCHEMA_VERSION)
        ->and($snapshot->registration['counts']['registeredStudents'])->toBe(3)
        ->and($snapshot->registration['counts']['registeredTeachers'])->toBe(1)
        ->and($snapshot->registration['counts']['engagedStudents'])->toBe(5)
        ->and($snapshot->registration['strandedPending'])->toBe([
            'count' => 2,
            'teachers' => [['teacherId' => $teacher->id, 'name' => 'Pat Director', 'count' => 2]],
        ]);
});

test('capture records who uploaded each recording type', function () {
    $version = Version::factory()->create(['event_id' => Event::factory()->create()->id, 'status' => 'closed']);
    $teacherUser = User::factory()->create();
    $studentUser = User::factory()->create();
    $candidate = Candidate::factory()->registered()->create([
        'version_id' => $version->id,
        'teacher_id' => Teacher::factory()->create(['user_id' => $teacherUser->id])->id,
        'student_id' => Student::factory()->create(['user_id' => $studentUser->id])->id,
    ]);

    $uploads = [['solo', $teacherUser->id], ['solo', $studentUser->id], ['scales', $teacherUser->id], ['scales', User::factory()->create()->id]];
    foreach ($uploads as [$type, $uploader]) {
        DB::table('recordings')->insert([
            'version_id' => $version->id,
            'candidate_id' => $candidate->id,
            'file_type' => $type,
            'uploaded_by' => $uploader,
            'url' => 'recording.mp3',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $snapshot = app(VersionAnalyticsSnapshotService::class)->capture($version);

    expect($snapshot->registration['uploads'])->toBe([
        ['type' => 'scales', 'student' => 0, 'teacher' => 1, 'other' => 1],
        ['type' => 'solo', 'student' => 1, 'teacher' => 1, 'other' => 0],
    ]);
});

test('selection keeps each decided candidate total by candidate id, with no student names anywhere in the snapshot', function () {
    $version = Version::factory()->create(['event_id' => Event::factory()->create()->id, 'status' => 'closed', 'cutoff_strategy' => 'grade_segmented_ensembles']);
    $voicePart = VoicePart::factory()->create();
    $studentUser = User::factory()->create(['first_name' => 'Zebulon', 'last_name' => 'Uniquename']);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'voice_part_id' => $voicePart->id,
        'student_id' => Student::factory()->create(['user_id' => $studentUser->id])->id,
        'status' => CandidateStatus::NotAccepted,
    ]);
    DB::table('audition_results')->insert([
        'candidate_id' => $candidate->id,
        'version_id' => $version->id,
        'voice_part_id' => $voicePart->id,
        'school_id' => $candidate->school_id,
        'voice_part_order_by' => 1,
        'score_count' => 9,
        'total' => 41,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $snapshot = app(VersionAnalyticsSnapshotService::class)->capture($version);

    expect($snapshot->selection['strategy'])->toBe('grade_segmented_ensembles')
        ->and($snapshot->selection['results'])->toBe([
            ['candidateId' => $candidate->id, 'voicePartId' => $voicePart->id, 'total' => 41, 'scoreCount' => 9, 'status' => 'not_accepted', 'ensembleId' => null],
        ])
        ->and($snapshot->selection['outcomes'])->toBe([['voicePartId' => $voicePart->id, 'status' => 'not_accepted', 'count' => 1]]);

    $row = DB::table('version_analytics_snapshots')->where('id', $snapshot->id)->first(['registration', 'adjudication', 'selection']);
    expect((string) $row?->registration.$row?->adjudication.$row?->selection)->not->toContain('Uniquename');
});

test('recapturing replaces the Version snapshot rather than adding a second one', function () {
    $version = Version::factory()->create(['event_id' => Event::factory()->create()->id, 'status' => 'closed']);
    $service = app(VersionAnalyticsSnapshotService::class);

    $service->capture($version, null, Carbon::parse('2026-11-20 12:00:00'));
    Candidate::factory()->create(['version_id' => $version->id, 'status' => CandidateStatus::Accepted]);
    $service->capture($version, null, Carbon::parse('2026-11-21 12:00:00'));

    $snapshots = VersionAnalyticsSnapshot::where('version_id', $version->id)->get();
    expect($snapshots)->toHaveCount(1)
        ->and($snapshots->first()?->captured_at->toDateTimeString())->toBe('2026-11-21 12:00:00')
        ->and($snapshots->first()?->registration['counts']['registeredStudents'])->toBe(1);
});

test('the job captures the snapshot stamped with the Close time and the closing user', function () {
    $version = Version::factory()->create(['event_id' => Event::factory()->create()->id, 'status' => 'closed']);
    $manager = User::factory()->create();

    $job = new CaptureVersionAnalyticsSnapshotJob($version->id, $manager->id, '2026-11-22T20:00:00+00:00');
    $job->handle(app(VersionAnalyticsSnapshotService::class));

    $snapshot = VersionAnalyticsSnapshot::where('version_id', $version->id)->firstOrFail();
    expect($snapshot->captured_by_user_id)->toBe($manager->id)
        ->and($snapshot->captured_at->toDateTimeString())->toBe('2026-11-22 20:00:00');
});

test('analytics:snapshot backfills a closed Version and refuses an open one', function () {
    $closed = Version::factory()->create(['event_id' => Event::factory()->create()->id, 'status' => 'closed', 'results_released_at' => '2026-11-22 21:00:00']);
    $open = Version::factory()->create(['event_id' => Event::factory()->create()->id, 'status' => 'active']);

    artisan('analytics:snapshot', ['version' => $closed->id])->assertSuccessful();
    artisan('analytics:snapshot', ['version' => $open->id])->assertFailed();

    expect(VersionAnalyticsSnapshot::where('version_id', $closed->id)->first()?->captured_at->toDateTimeString())->toBe('2026-11-22 21:00:00')
        ->and(VersionAnalyticsSnapshot::where('version_id', $open->id)->exists())->toBeFalse();
});
