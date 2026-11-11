<?php

declare(strict_types=1);

use App\Enums\CandidateUploadStatus;
use App\Livewire\Events\VersionVoicePartAudit;
use App\Models\Candidate;
use App\Models\CandidateUploadFile;
use App\Models\CoRegistrationManagerCounty;
use App\Models\County;
use App\Models\Ensemble;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionUploadFile;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * One Version offering Soprano I / Soprano II, with a legacy "Soprano" part
 * offered by no Ensemble, and two counties' schools.
 *
 * @return array{version: Version, slot: VersionUploadFile, parts: array<string, VoicePart>, counties: array<string, County>}
 */
function versionVoicePartAuditFixture(): array
{
    $version = Version::factory()->create();
    $parts = [];

    foreach (['Soprano' => 2, 'Soprano I' => 3, 'Soprano II' => 4] as $name => $order) {
        $parts[$name] = VoicePart::factory()->create(['name' => $name, 'sort_order' => $order]);
    }

    Ensemble::factory()->create(['event_id' => $version->event_id])
        ->voiceParts()->attach([$parts['Soprano I']->id, $parts['Soprano II']->id]);

    return [
        'version' => $version,
        'slot' => VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]),
        'parts' => $parts,
        'counties' => ['a' => County::factory()->create(), 'b' => County::factory()->create()],
    ];
}

function versionVoicePartAuditCandidate(array $fixture, string $county, string $lastName): Candidate
{
    actingAs(makeFounder());
    $teacher = Teacher::factory()->create(['user_id' => User::factory()->create()->id]);

    $candidate = Candidate::factory()->create([
        'version_id' => $fixture['version']->id,
        'teacher_id' => $teacher->id,
        'school_id' => School::factory()->create(['county_id' => $fixture['counties'][$county]->id])->id,
        'voice_part_id' => $fixture['parts']['Soprano']->id,
    ]);
    $candidate->student->user->update(['first_name' => 'Pat', 'last_name' => $lastName]);

    CandidateUploadFile::create([
        'candidate_id' => $candidate->id,
        'version_upload_file_id' => $fixture['slot']->id,
        'url' => "candidateUploads/{$candidate->id}/scales.mp3",
        'original_filename' => "{$lastName} scales.mp3",
        'status' => CandidateUploadStatus::Approved->value,
        'uploaded_at' => now(),
    ]);

    return $candidate;
}

test('a user with no role on the Version gets 403', function () {
    $fixture = versionVoicePartAuditFixture();

    Livewire::actingAs(User::factory()->create())
        ->test(VersionVoicePartAudit::class, ['version' => $fixture['version']])
        ->assertStatus(403);
});

test('a Registration Manager sees every county and can change a voice part', function () {
    $fixture = versionVoicePartAuditFixture();
    $inA = versionVoicePartAuditCandidate($fixture, 'a', 'Countya');
    versionVoicePartAuditCandidate($fixture, 'b', 'Countyb');

    $manager = User::factory()->create();
    grantVersionRole($manager, $fixture['version'], 'Registration Manager');

    Livewire::actingAs($manager)
        ->test(VersionVoicePartAudit::class, ['version' => $fixture['version']])
        ->assertSee('Countya')
        ->assertSee('Countyb')
        ->assertDontSee('Select a version...')
        ->call('changeVoicePart', $inA->id, (string) $fixture['parts']['Soprano I']->id);

    expect($inA->refresh()->voice_part_id)->toBe($fixture['parts']['Soprano I']->id);
});

test('a Co-Registration Manager sees and changes only candidates in their counties', function () {
    $fixture = versionVoicePartAuditFixture();
    $inA = versionVoicePartAuditCandidate($fixture, 'a', 'Countya');
    $inB = versionVoicePartAuditCandidate($fixture, 'b', 'Countyb');

    $coManager = User::factory()->create();
    grantVersionRole($coManager, $fixture['version'], 'Co-Registration Manager');
    CoRegistrationManagerCounty::create([
        'version_id' => $fixture['version']->id,
        'user_id' => $coManager->id,
        'county_id' => $fixture['counties']['a']->id,
    ]);

    Livewire::actingAs($coManager)
        ->test(VersionVoicePartAudit::class, ['version' => $fixture['version']])
        ->assertSee('Countya')
        ->assertDontSee('Countyb')
        ->call('changeVoicePart', $inB->id, (string) $fixture['parts']['Soprano I']->id)
        ->assertSet('refreshNonce', 1)
        ->call('changeVoicePart', $inA->id, (string) $fixture['parts']['Soprano II']->id);

    expect($inB->refresh()->voice_part_id)->toBe($fixture['parts']['Soprano']->id)
        ->and($inA->refresh()->voice_part_id)->toBe($fixture['parts']['Soprano II']->id);
});

test('a Web Registration Manager who is not a Registration Manager sees every county', function () {
    $fixture = versionVoicePartAuditFixture();
    versionVoicePartAuditCandidate($fixture, 'a', 'Countya');
    versionVoicePartAuditCandidate($fixture, 'b', 'Countyb');

    $webRegManager = User::factory()->create();
    grantVersionRole($webRegManager, $fixture['version'], 'Web Registration Manager');

    Livewire::actingAs($webRegManager)
        ->test(VersionVoicePartAudit::class, ['version' => $fixture['version']])
        ->assertSee('Countya')
        ->assertSee('Countyb');
});

test('the bound version cannot be swapped from the browser', function () {
    $fixture = versionVoicePartAuditFixture();
    $manager = User::factory()->create();
    grantVersionRole($manager, $fixture['version'], 'Registration Manager');

    Livewire::actingAs($manager)
        ->test(VersionVoicePartAudit::class, ['version' => $fixture['version']])
        ->set('versionId', Version::factory()->create()->id);
})->throws(CannotUpdateLockedPropertyException::class);
