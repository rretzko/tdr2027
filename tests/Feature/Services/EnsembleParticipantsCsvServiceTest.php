<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\EventStatus;
use App\Jobs\GenerateEnsembleParticipantsCsvJob;
use App\Livewire\Events\Reports\Downloads;
use App\Livewire\Events\TabRoom\CloseAudition;
use App\Models\Candidate;
use App\Models\EmergencyContact;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\Organization;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionEnsembleOrder;
use App\Models\VersionEnsembleParticipantExport;
use App\Models\VoicePart;
use App\Services\EnsembleParticipantsCsvService;
use App\Services\VersionRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('s3'));

/**
 * @return array{version: Version, ensemble: Ensemble, manager: User}
 */
function participantsScenario(string $scoreOrder = 'desc'): array
{
    $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => EventStatus::Closed, 'score_order' => $scoreOrder]);
    $ensemble = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Mixed Chorus', 'abbreviation' => 'mx']);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $ensemble->id, 'order_by' => 1]);
    $manager = User::factory()->create();
    app(VersionRoleService::class)->withVersion($version, fn () => $manager->assignRole('Event Manager'));

    return ['version' => $version, 'ensemble' => $ensemble, 'manager' => $manager];
}

function participantsCandidate(Version $version, ?Ensemble $ensemble, VoicePart $voicePart, int $total, string $lastName, CandidateStatus $status = CandidateStatus::Accepted, ?string $cellPhone = null): Candidate
{
    $school = School::factory()->create();
    $student = Student::factory()->create(['user_id' => User::factory()->create(['first_name' => 'Pat', 'last_name' => $lastName, 'cell_phone' => $cellPhone])->id]);
    $student->schools()->attach($school->id, ['is_active' => true, 'class_of' => $school->senior_year + 1]);

    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'voice_part_id' => $voicePart->id,
        'school_id' => $school->id,
        'student_id' => $student->id,
        'status' => $status,
        'accepted_ensemble_id' => $ensemble?->id,
    ]);

    DB::table('audition_results')->insert([
        'candidate_id' => $candidate->id, 'version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'school_id' => $school->id,
        'voice_part_order_by' => 1, 'score_count' => 6, 'total' => $total, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $candidate;
}

test('the header matches the sample file layout with three emergency contacts, minus student home and teacher work', function () {
    expect(EnsembleParticipantsCsvService::HEADER)->toHaveCount(28)
        ->and(array_slice(EnsembleParticipantsCsvService::HEADER, 0, 9))->toBe(['name', 'lastName', 'class of', 'grade', 'school', 'teacher', 'vp', 'vp-sort', 'score'])
        ->and(array_slice(EnsembleParticipantsCsvService::HEADER, 9, 4))->toBe(['student email', 'student cell', 'teacher email', 'teacher cell'])
        ->and(array_slice(EnsembleParticipantsCsvService::HEADER, -5))->toBe(['ec name3', 'ec email3', 'ec cell3', 'ec home3', 'ec work3']);
});

test('rows hold only accepted candidates of the ensemble, by voice part then best score then name', function () {
    ['version' => $version, 'ensemble' => $ensemble] = participantsScenario('desc');
    $other = Ensemble::factory()->create(['event_id' => $version->event_id]);
    $sopranos = VoicePart::factory()->create(['abbr' => 'S1', 'sort_order' => 1]);
    $altos = VoicePart::factory()->create(['abbr' => 'A1', 'sort_order' => 2]);

    participantsCandidate($version, $ensemble, $altos, 90, 'Alto');
    participantsCandidate($version, $ensemble, $sopranos, 80, 'Low');
    participantsCandidate($version, $ensemble, $sopranos, 95, 'Zed');
    participantsCandidate($version, $ensemble, $sopranos, 95, 'Abel');
    participantsCandidate($version, $other, $sopranos, 99, 'Elsewhere');
    participantsCandidate($version, null, $sopranos, 70, 'Missed', CandidateStatus::NotAccepted);

    $rows = app(EnsembleParticipantsCsvService::class)->rows($version, $ensemble);

    expect(array_map(fn (array $row): string => $row['vp'].' '.$row['score'].' '.$row['lastName'], $rows))
        ->toBe(['S1 95 Abel', 'S1 95 Zed', 'S1 80 Low', 'A1 90 Alto']);
});

test('lower-is-better Versions put the lowest total first', function () {
    ['version' => $version, 'ensemble' => $ensemble] = participantsScenario('asc');
    $voicePart = VoicePart::factory()->create(['sort_order' => 1]);
    participantsCandidate($version, $ensemble, $voicePart, 90, 'High');
    participantsCandidate($version, $ensemble, $voicePart, 60, 'Low');

    $rows = app(EnsembleParticipantsCsvService::class)->rows($version, $ensemble);

    expect(array_column($rows, 'lastName'))->toBe(['Low', 'High']);
});

test('a row carries class of, grade, formatted phones and up to three emergency contacts, chosen contact first', function () {
    ['version' => $version, 'ensemble' => $ensemble] = participantsScenario();
    $candidate = participantsCandidate($version, $ensemble, VoicePart::factory()->create(), 88, 'Singer', cellPhone: '9735551234');

    $contacts = collect(['First', 'Second', 'Third', 'Fourth'])->map(fn (string $name): EmergencyContact => EmergencyContact::create([
        'student_id' => $candidate->student_id, 'name' => "{$name} Parent", 'email' => strtolower($name).'@example.com', 'cell_phone' => '2015550100', 'relationship' => 'mother',
    ]));
    $candidate->update(['emergency_contact_id' => $contacts[2]->id]);

    $row = app(EnsembleParticipantsCsvService::class)->rows($version, $ensemble)[0];

    expect($row['class of'])->toBe((string) ($candidate->school->senior_year + 1))
        ->and($row['grade'])->toBe('11')
        ->and($row['score'])->toBe('88')
        ->and($row['student cell'])->toBe('(973) 555-1234')
        ->and($row['emergency contact'])->toBe('Third Parent')
        ->and($row['ec name2'])->toBe('First Parent')
        ->and($row['ec name3'])->toBe('Second Parent')
        ->and($row['ec cell1'])->toBe('(201) 555-0100')
        ->and(implode(',', $row))->not->toContain('Fourth Parent');
});

test('generating stores one CSV per ensemble and regenerating replaces the file', function () {
    ['version' => $version, 'ensemble' => $ensemble] = participantsScenario();
    participantsCandidate($version, $ensemble, VoicePart::factory()->create(), 88, 'Singer');
    $service = app(EnsembleParticipantsCsvService::class);

    $first = $service->generateAll($version, null, Carbon::parse('2026-11-22 20:00:00'))[0];
    expect($first->filename)->toBe('mx_ensembleParticipants_20261122_200000.csv')
        ->and($first->row_count)->toBe(1);
    Storage::disk('s3')->assertExists($first->s3_key);
    $lines = explode("\n", trim((string) Storage::disk('s3')->get($first->s3_key)));
    expect($lines)->toHaveCount(2)
        ->and(str_getcsv($lines[0], escape: ''))->toBe(EnsembleParticipantsCsvService::HEADER);

    $second = $service->generateAll($version, null, Carbon::parse('2026-11-23 09:00:00'))[0];
    Storage::disk('s3')->assertMissing($first->s3_key);
    Storage::disk('s3')->assertExists($second->s3_key);
    expect(VersionEnsembleParticipantExport::where('version_id', $version->id)->count())->toBe(1);
});

test('closing the audition queues the CSV build', function () {
    Queue::fake();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    $manager = User::factory()->create();
    actingAs($manager);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    Livewire::actingAs($manager)->test(CloseAudition::class, ['version' => $version])->call('close');

    Queue::assertPushed(GenerateEnsembleParticipantsCsvJob::class, 1);
});

test('the Downloads card regenerates the files and each ensemble downloads its own', function () {
    ['version' => $version, 'ensemble' => $ensemble, 'manager' => $manager] = participantsScenario();
    participantsCandidate($version, $ensemble, VoicePart::factory()->create(), 88, 'Singer');

    Livewire::actingAs($manager)
        ->test(Downloads::class, ['version' => $version])
        ->assertSee('Ensemble Participants')
        ->assertSee('Created when the audition is closed.')
        ->call('regenerateEnsembleParticipants')
        ->assertSee('1 accepted');

    $response = actingAs($manager)->get(route('events.versions.reports.downloads.ensemble-participants', ['version' => $version, 'ensemble' => $ensemble]));
    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('mx_ensembleParticipants_');
});

test('regenerating with nobody accepted explains instead of building empty files', function () {
    ['version' => $version, 'manager' => $manager] = participantsScenario();

    Livewire::actingAs($manager)
        ->test(Downloads::class, ['version' => $version])
        ->call('regenerateEnsembleParticipants');

    expect(VersionEnsembleParticipantExport::count())->toBe(0);
});

test('downloads are refused to someone without Downloads access and 404 before a file exists', function () {
    ['version' => $version, 'ensemble' => $ensemble, 'manager' => $manager] = participantsScenario();
    $url = route('events.versions.reports.downloads.ensemble-participants', ['version' => $version, 'ensemble' => $ensemble]);

    actingAs($manager)->get($url)->assertNotFound();
    actingAs(User::factory()->create())->get($url)->assertForbidden();
});
