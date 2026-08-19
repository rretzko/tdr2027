<?php

declare(strict_types=1);

use App\Enums\VersionObligationStatus;
use App\Livewire\Registrations\PitchFiles;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VersionObligation;
use App\Models\VersionPitchFile;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makePitchFilesTeacher(): Teacher
{
    $user = User::factory()->create();

    return Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
}

function invitePitchFilesTeacher(Teacher $teacher, Version $version): void
{
    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => 'invited',
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);
}

function makeRegistrationsPitchFilesVersion(): Version
{
    $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    return Version::factory()->create(['event_id' => $event->id]);
}

/**
 * Attaches a fresh VoicePart to the Version's Event via a fresh Ensemble, so
 * it shows up in Version::availableVoiceParts() — same helper as the admin
 * VersionPitchFilesTest.
 */
function attachVoicePartToPitchFilesVersion(Version $version, ?string $name = null): VoicePart
{
    $voicePart = $name !== null
        ? VoicePart::factory()->create(['name' => $name, 'abbr' => $name])
        : VoicePart::factory()->create();
    $ensemble = Ensemble::factory()->create(['event_id' => $version->event_id]);
    $ensemble->voiceParts()->attach($voicePart->id);

    return $voicePart;
}

test('mount aborts with 403 for a teacher with no invitation on this Version', function () {
    $teacher = makePitchFilesTeacher();
    $version = makeRegistrationsPitchFilesVersion();
    actingAs($teacher->user);

    Livewire::test(PitchFiles::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount redirects an invited teacher who has not yet responded to a published obligation', function () {
    $teacher = makePitchFilesTeacher();
    $version = makeRegistrationsPitchFilesVersion();
    actingAs($teacher->user);
    invitePitchFilesTeacher($teacher, $version);
    VersionObligation::create([
        'version_id' => $version->id,
        'body' => '<p>Be excellent.</p>',
        'status' => VersionObligationStatus::Published->value,
        'published_at' => now(),
        'published_by_user_id' => User::factory()->create()->id,
    ]);

    Livewire::test(PitchFiles::class, ['version' => $version])
        ->assertRedirect(route('registrations.obligations', $version));
});

test('an invited teacher sees every pitch file with no filter applied', function () {
    Storage::fake('s3');

    $teacher = makePitchFilesTeacher();
    $version = makeRegistrationsPitchFilesVersion();
    actingAs($teacher->user);
    invitePitchFilesTeacher($teacher, $version);

    $soprano = attachVoicePartToPitchFilesVersion($version, 'Soprano');
    VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $soprano->id, 'name' => 'scales', 'url' => 'x', 'order_by' => 1]);

    Livewire::test(PitchFiles::class, ['version' => $version])
        ->assertSee('scales')
        ->assertSee('Soprano');
});

test('the voice part filter narrows the list to matching pitch files', function () {
    Storage::fake('s3');

    $teacher = makePitchFilesTeacher();
    $version = makeRegistrationsPitchFilesVersion();
    actingAs($teacher->user);
    invitePitchFilesTeacher($teacher, $version);

    $soprano = attachVoicePartToPitchFilesVersion($version, 'Soprano');
    $alto = attachVoicePartToPitchFilesVersion($version, 'Alto');
    VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $soprano->id, 'name' => 'soprano-scales', 'url' => 'x', 'order_by' => 1]);
    VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $alto->id, 'name' => 'alto-scales', 'url' => 'y', 'order_by' => 2]);

    // assertDontSee would false-fail here — the excluded name still appears
    // as an <option> in the (unfiltered-by-voice-part) name-filter dropdown
    // — so inspect the filtered collection the component actually built.
    $names = Livewire::test(PitchFiles::class, ['version' => $version])
        ->set('voicePartFilter', (string) $soprano->id)
        ->viewData('pitchFiles')
        ->pluck('name');

    expect($names)->toContain('soprano-scales');
    expect($names)->not->toContain('alto-scales');
});

test('a pitch file on the ALL voice part is shown regardless of the voice part filter', function () {
    Storage::fake('s3');

    $teacher = makePitchFilesTeacher();
    $version = makeRegistrationsPitchFilesVersion();
    actingAs($teacher->user);
    invitePitchFilesTeacher($teacher, $version);

    $soprano = attachVoicePartToPitchFilesVersion($version, 'Soprano');
    $alto = attachVoicePartToPitchFilesVersion($version, 'Alto');
    $all = attachVoicePartToPitchFilesVersion($version, 'ALL');
    VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $alto->id, 'name' => 'alto-scales', 'url' => 'x', 'order_by' => 1]);
    VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $all->id, 'name' => 'general-warmup', 'url' => 'y', 'order_by' => 2]);

    $names = Livewire::test(PitchFiles::class, ['version' => $version])
        ->set('voicePartFilter', (string) $soprano->id)
        ->viewData('pitchFiles')
        ->pluck('name');

    expect($names)->toContain('general-warmup');
    expect($names)->not->toContain('alto-scales');
});

test('the name filter narrows the list to an exact name match', function () {
    Storage::fake('s3');

    $teacher = makePitchFilesTeacher();
    $version = makeRegistrationsPitchFilesVersion();
    actingAs($teacher->user);
    invitePitchFilesTeacher($teacher, $version);

    $voicePart = attachVoicePartToPitchFilesVersion($version);
    VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'name' => 'scales', 'url' => 'x', 'order_by' => 1]);
    VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'name' => 'solo', 'url' => 'y', 'order_by' => 2]);

    $names = Livewire::test(PitchFiles::class, ['version' => $version])
        ->set('nameFilter', 'solo')
        ->viewData('pitchFiles')
        ->pluck('name');

    expect($names)->toContain('solo');
    expect($names)->not->toContain('scales');
});

test('shows the empty-state message when the Version has no pitch files', function () {
    $teacher = makePitchFilesTeacher();
    $version = makeRegistrationsPitchFilesVersion();
    actingAs($teacher->user);
    invitePitchFilesTeacher($teacher, $version);

    Livewire::test(PitchFiles::class, ['version' => $version])
        ->assertSee('No pitch files have been added to this Version yet.');
});

// --- pitch_file_visibility (studentfolder-module.md §5.5, second pass 2026-08-18) ---

test('a Version set to Candidate-only visibility shows no pitch files to the teacher', function () {
    Storage::fake('s3');

    $teacher = makePitchFilesTeacher();
    $version = makeRegistrationsPitchFilesVersion();
    $version->update(['pitch_file_visibility' => 'candidate']);
    actingAs($teacher->user);
    invitePitchFilesTeacher($teacher, $version);

    $soprano = attachVoicePartToPitchFilesVersion($version, 'Soprano');
    VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $soprano->id, 'name' => 'scales', 'url' => 'x', 'order_by' => 1]);

    Livewire::test(PitchFiles::class, ['version' => $version])
        ->assertDontSee('scales')
        ->assertSee('only shown to candidates');
});

test('a Version set to Teacher-only or Both visibility still shows pitch files to the teacher', function (string $visibility) {
    Storage::fake('s3');

    $teacher = makePitchFilesTeacher();
    $version = makeRegistrationsPitchFilesVersion();
    $version->update(['pitch_file_visibility' => $visibility]);
    actingAs($teacher->user);
    invitePitchFilesTeacher($teacher, $version);

    $soprano = attachVoicePartToPitchFilesVersion($version, 'Soprano');
    VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $soprano->id, 'name' => 'scales', 'url' => 'x', 'order_by' => 1]);

    Livewire::test(PitchFiles::class, ['version' => $version])
        ->assertSee('scales');
})->with(['teacher', 'both']);
