<?php

declare(strict_types=1);

use App\Concerns\HasCandidateChecklist;
use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\EnsembleGrade;
use App\Models\School;
use App\Models\User;
use App\Models\Version;
use App\Models\VoicePart;
use App\Services\CandidateService;
use App\Support\ClassOfCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * @return array{label: string, check: Closure(Candidate): bool, partial?: Closure(Candidate): bool, gate?: bool}|null
 */
function voicePartChecklistItem(Version $version): ?array
{
    $host = new class
    {
        use HasCandidateChecklist;
    };

    return collect($host->checklistDefs($version))->firstWhere('label', 'Voice part valid for grade');
}

/**
 * High School Chorus (grades 9–12) offers Soprano I; Middle School Chorus
 * (grades 6–8) offers Soprano — the Version 93 shape.
 *
 * @return array{version: Version, soprano: VoicePart, sopranoI: VoicePart}
 */
function voicePartChecklistFixture(): array
{
    // Only Program name + this gate on the checklist (no emergency contact).
    $version = Version::factory()->create(['emergency_contact_name' => false]);
    $soprano = VoicePart::factory()->create(['name' => 'Soprano', 'sort_order' => 2]);
    $sopranoI = VoicePart::factory()->create(['name' => 'Soprano I', 'sort_order' => 3]);

    $high = Ensemble::factory()->create(['event_id' => $version->event_id]);
    $high->voiceParts()->attach($sopranoI->id);
    foreach ([9, 10, 11, 12] as $grade) {
        EnsembleGrade::create(['ensemble_id' => $high->id, 'grade' => $grade]);
    }

    $middle = Ensemble::factory()->create(['event_id' => $version->event_id]);
    $middle->voiceParts()->attach($soprano->id);
    foreach ([6, 7, 8] as $grade) {
        EnsembleGrade::create(['ensemble_id' => $middle->id, 'grade' => $grade]);
    }

    return ['version' => $version, 'soprano' => $soprano, 'sopranoI' => $sopranoI];
}

/** A Candidate whose student is in $grade at an active school. */
function voicePartChecklistCandidate(Version $version, VoicePart $voicePart, int $grade, CandidateStatus $status = CandidateStatus::Eligible): Candidate
{
    actingAs(User::factory()->create());

    $school = School::factory()->create();
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'voice_part_id' => $voicePart->id,
        'status' => $status,
        'program_name' => '',
    ]);

    DB::table('school_student')->where('student_id', $candidate->student_id)->delete();
    DB::table('school_student')->insert([
        'student_id' => $candidate->student_id,
        'school_id' => $school->id,
        'is_active' => true,
        'class_of' => ClassOfCalculator::classOfFromGrade($grade, $school->senior_year),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $candidate->fresh();
}

test('the item is a gate that passes only for a voice part offered to the grade', function () {
    $fixture = voicePartChecklistFixture();
    $item = voicePartChecklistItem($fixture['version']);

    $legacy = voicePartChecklistCandidate($fixture['version'], $fixture['soprano'], 10);
    $correct = voicePartChecklistCandidate($fixture['version'], $fixture['sopranoI'], 10);
    $middleSchooler = voicePartChecklistCandidate($fixture['version'], $fixture['soprano'], 8);

    expect($legacy->student->grade)->toBe(10)
        ->and($item)->not->toBeNull()
        ->and($item['gate'] ?? false)->toBeTrue()
        ->and(($item['check'])($legacy))->toBeFalse()
        ->and(($item['check'])($correct))->toBeTrue()
        ->and(($item['check'])($middleSchooler))->toBeTrue();
});

test('the item is left out when the Version has no Ensemble voice parts', function () {
    expect(voicePartChecklistItem(Version::factory()->create()))->toBeNull();
});

test('a grade-invalid voice part keeps an otherwise complete Candidate out of Registered', function () {
    $fixture = voicePartChecklistFixture();
    $candidate = voicePartChecklistCandidate($fixture['version'], $fixture['soprano'], 10, CandidateStatus::Registered);
    $candidate->update(['program_name' => 'Pat Singer']);

    $host = new class
    {
        use HasCandidateChecklist;
    };
    $defs = $host->checklistDefs($fixture['version']);
    $service = new CandidateService;

    $service->recalculateStatus($candidate->refresh(), $defs);
    expect($candidate->refresh()->status)->toBe(CandidateStatus::Pending);

    $candidate->update(['voice_part_id' => $fixture['sopranoI']->id]);
    $service->recalculateStatus($candidate->refresh(), $host->checklistDefs($fixture['version']));
    expect($candidate->refresh()->status)->toBe(CandidateStatus::Registered);
});
