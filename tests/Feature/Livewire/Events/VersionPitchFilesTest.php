<?php

declare(strict_types=1);

use App\Livewire\Events\VersionPitchFiles;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionPitchFile;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('s3');
});

function makePitchFilesVersion(): Version
{
    $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    return Version::factory()->create(['event_id' => $event->id]);
}

/**
 * Attaches a fresh VoicePart to the Version's Event via a fresh Ensemble,
 * so it shows up in Version::availableVoiceParts().
 */
function attachVoicePartToVersion(Version $version): VoicePart
{
    $voicePart = VoicePart::factory()->create();
    $ensemble = Ensemble::factory()->create(['event_id' => $version->event_id]);
    $ensemble->voiceParts()->attach($voicePart->id);

    return $voicePart;
}

test('mount aborts with 403 for a user with no version-scoped role on the Version', function () {
    $user = User::factory()->create();
    $version = makePitchFilesVersion();

    Livewire::actingAs($user)
        ->test(VersionPitchFiles::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount allows a user holding Event Manager on the Version', function () {
    $user = User::factory()->create();
    $version = makePitchFilesVersion();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionPitchFiles::class, ['version' => $version])
        ->assertOk();
});

test('mount allows the Founder regardless of any role assignment', function () {
    $founder = makeFounder();
    $version = makePitchFilesVersion();

    Livewire::actingAs($founder)
        ->test(VersionPitchFiles::class, ['version' => $version])
        ->assertOk();
});

test('save creates a new pitch file, uploads to S3, and assigns the next order_by', function () {
    Storage::fake('s3');

    $eventManager = User::factory()->create();
    $version = makePitchFilesVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $voicePart = attachVoicePartToVersion($version);

    $file = UploadedFile::fake()->create('audition.mp3', 500, 'audio/mpeg');

    Livewire::actingAs($eventManager)
        ->test(VersionPitchFiles::class, ['version' => $version])
        ->set('name', 'scales')
        ->set('voice_part_id', (string) $voicePart->id)
        ->set('description', 'Major scales')
        ->set('newFile', $file)
        ->call('save')
        ->assertDispatched('toast-show', slots: ['text' => '"scales" saved.']);

    $pitchFile = VersionPitchFile::where('version_id', $version->id)->first();

    expect($pitchFile)->not->toBeNull();
    expect($pitchFile->name)->toBe('scales');
    expect($pitchFile->voice_part_id)->toBe($voicePart->id);
    expect($pitchFile->description)->toBe('Major scales');
    expect($pitchFile->order_by)->toBe(1);
    Storage::disk('s3')->assertExists($pitchFile->url);
});

test('save rejects a voice_part_id that is not attached to any Ensemble on the Version\'s Event', function () {
    Storage::fake('s3');

    $eventManager = User::factory()->create();
    $version = makePitchFilesVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $unrelatedVoicePart = VoicePart::factory()->create();
    $file = UploadedFile::fake()->create('audition.mp3', 500, 'audio/mpeg');

    Livewire::actingAs($eventManager)
        ->test(VersionPitchFiles::class, ['version' => $version])
        ->set('name', 'scales')
        ->set('voice_part_id', (string) $unrelatedVoicePart->id)
        ->set('newFile', $file)
        ->call('save')
        ->assertHasErrors(['voice_part_id']);

    expect(VersionPitchFile::where('version_id', $version->id)->exists())->toBeFalse();
});

test('edit prefills the form fields from the existing pitch file', function () {
    $eventManager = User::factory()->create();
    $version = makePitchFilesVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $voicePart = attachVoicePartToVersion($version);

    $pitchFile = VersionPitchFile::create([
        'version_id' => $version->id,
        'voice_part_id' => $voicePart->id,
        'name' => 'quartet',
        'description' => 'Full quartet mix',
        'url' => 'pitchFiles/1/existing.mp3',
        'order_by' => 1,
    ]);

    Livewire::actingAs($eventManager)
        ->test(VersionPitchFiles::class, ['version' => $version])
        ->call('edit', $pitchFile->id)
        ->assertSet('editingId', $pitchFile->id)
        ->assertSet('name', 'quartet')
        ->assertSet('voice_part_id', (string) $voicePart->id)
        ->assertSet('description', 'Full quartet mix');
});

test('save on an existing pitch file replaces the file and deletes the old S3 object', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('pitchFiles/1/old.mp3', 'old-content');

    $eventManager = User::factory()->create();
    $version = makePitchFilesVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $voicePart = attachVoicePartToVersion($version);

    $pitchFile = VersionPitchFile::create([
        'version_id' => $version->id,
        'voice_part_id' => $voicePart->id,
        'name' => 'quartet',
        'description' => null,
        'url' => 'pitchFiles/1/old.mp3',
        'order_by' => 1,
    ]);

    $newFile = UploadedFile::fake()->create('new-audition.mp3', 500, 'audio/mpeg');

    Livewire::actingAs($eventManager)
        ->test(VersionPitchFiles::class, ['version' => $version])
        ->call('edit', $pitchFile->id)
        ->set('newFile', $newFile)
        ->call('save');

    $pitchFile->refresh();

    Storage::disk('s3')->assertMissing('pitchFiles/1/old.mp3');
    Storage::disk('s3')->assertExists($pitchFile->url);
    expect($pitchFile->url)->not->toBe('pitchFiles/1/old.mp3');
});

test('remove deletes the S3 object and the row', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('pitchFiles/1/gone.mp3', 'content');

    $eventManager = User::factory()->create();
    $version = makePitchFilesVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $voicePart = attachVoicePartToVersion($version);

    $pitchFile = VersionPitchFile::create([
        'version_id' => $version->id,
        'voice_part_id' => $voicePart->id,
        'name' => 'solo',
        'url' => 'pitchFiles/1/gone.mp3',
        'order_by' => 1,
    ]);

    Livewire::actingAs($eventManager)
        ->test(VersionPitchFiles::class, ['version' => $version])
        ->call('remove', $pitchFile->id)
        ->assertDispatched('toast-show', slots: ['text' => '"solo" removed.']);

    expect(VersionPitchFile::find($pitchFile->id))->toBeNull();
    Storage::disk('s3')->assertMissing('pitchFiles/1/gone.mp3');
});

test('search matches by name and description', function () {
    $eventManager = User::factory()->create();
    $version = makePitchFilesVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $voicePart = attachVoicePartToVersion($version);

    VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'name' => 'scales', 'url' => 'x', 'order_by' => 1]);
    VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'name' => 'solo', 'url' => 'y', 'order_by' => 2]);

    Livewire::actingAs($eventManager)
        ->test(VersionPitchFiles::class, ['version' => $version])
        ->set('search', 'scales')
        ->assertViewHas('pitchFiles', fn ($pitchFiles) => $pitchFiles->pluck('name')->all() === ['scales']);
});

test('voicePartFilter narrows the list to the selected voice part', function () {
    $eventManager = User::factory()->create();
    $version = makePitchFilesVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $soprano = attachVoicePartToVersion($version);
    $alto = attachVoicePartToVersion($version);

    VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $soprano->id, 'name' => 'soprano-scales', 'url' => 'x', 'order_by' => 1]);
    VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $alto->id, 'name' => 'alto-scales', 'url' => 'y', 'order_by' => 2]);

    Livewire::actingAs($eventManager)
        ->test(VersionPitchFiles::class, ['version' => $version])
        ->set('voicePartFilter', (string) $soprano->id)
        ->assertViewHas('pitchFiles', fn ($pitchFiles) => $pitchFiles->pluck('name')->all() === ['soprano-scales']);
});

test('nameFilter narrows the list to the selected file type', function () {
    $eventManager = User::factory()->create();
    $version = makePitchFilesVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $voicePart = attachVoicePartToVersion($version);

    VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'name' => 'scales', 'url' => 'x', 'order_by' => 1]);
    VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'name' => 'solo', 'url' => 'y', 'order_by' => 2]);

    Livewire::actingAs($eventManager)
        ->test(VersionPitchFiles::class, ['version' => $version])
        ->set('nameFilter', 'solo')
        ->assertViewHas('pitchFiles', fn ($pitchFiles) => $pitchFiles->pluck('name')->all() === ['solo']);
});

test('sortBy toggles direction on the same column and resets to asc on a new column', function () {
    $eventManager = User::factory()->create();
    $version = makePitchFilesVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');

    Livewire::actingAs($eventManager)
        ->test(VersionPitchFiles::class, ['version' => $version])
        ->assertSet('sortColumn', 'order_by')
        ->assertSet('sortDirection', 'asc')
        ->call('sortBy', 'order_by')
        ->assertSet('sortDirection', 'desc')
        ->call('sortBy', 'name')
        ->assertSet('sortColumn', 'name')
        ->assertSet('sortDirection', 'asc');
});

test('saveOrder bulk-persists order_by from the numeric inputs', function () {
    $eventManager = User::factory()->create();
    $version = makePitchFilesVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $voicePart = attachVoicePartToVersion($version);

    $first = VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'name' => 'scales', 'url' => 'x', 'order_by' => 1]);
    $second = VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'name' => 'solo', 'url' => 'y', 'order_by' => 2]);

    Livewire::actingAs($eventManager)
        ->test(VersionPitchFiles::class, ['version' => $version])
        ->set("orderInputs.{$first->id}", 2)
        ->set("orderInputs.{$second->id}", 1)
        ->call('saveOrder')
        ->assertDispatched('toast-show', slots: ['text' => 'Pitch file order saved.']);

    expect($first->fresh()->order_by)->toBe(2);
    expect($second->fresh()->order_by)->toBe(1);
});

test('reorderPitchFiles moves the dragged item to its new position and resequences order_by', function () {
    $eventManager = User::factory()->create();
    $version = makePitchFilesVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $voicePart = attachVoicePartToVersion($version);

    $first = VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'name' => 'a', 'url' => 'x', 'order_by' => 1]);
    $second = VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'name' => 'b', 'url' => 'y', 'order_by' => 2]);
    $third = VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'name' => 'c', 'url' => 'z', 'order_by' => 3]);

    Livewire::actingAs($eventManager)
        ->test(VersionPitchFiles::class, ['version' => $version])
        ->call('reorderPitchFiles', $first->id, 2);

    expect($second->fresh()->order_by)->toBe(1);
    expect($third->fresh()->order_by)->toBe(2);
    expect($first->fresh()->order_by)->toBe(3);
});
