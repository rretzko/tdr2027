<?php

declare(strict_types=1);

use App\Enums\JudgeStatus;
use App\Enums\JudgeType;
use App\Livewire\Events\VersionRooms;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\Organization;
use App\Models\RoomJudge;
use App\Models\ScoreCategory;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionRoom;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeRoomsVersion(): Version
{
    $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    return Version::factory()->create(['event_id' => $event->id]);
}

/**
 * Attaches a fresh VoicePart to the Version's Event via a fresh Ensemble,
 * so it shows up in Version::availableVoiceParts().
 */
function attachVoicePartToRoomsVersion(Version $version): VoicePart
{
    $voicePart = VoicePart::factory()->create();
    $ensemble = Ensemble::factory()->create(['event_id' => $version->event_id]);
    $ensemble->voiceParts()->attach($voicePart->id);

    return $voicePart;
}

test('mount aborts with 403 for a user with no version-scoped role on the Version', function () {
    $user = User::factory()->create();
    $version = makeRoomsVersion();

    Livewire::actingAs($user)
        ->test(VersionRooms::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount allows a user holding Event Manager on the Version', function () {
    $user = User::factory()->create();
    $version = makeRoomsVersion();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionRooms::class, ['version' => $version])
        ->assertOk();
});

test('mount allows the Founder regardless of any role assignment', function () {
    $founder = makeFounder();
    $version = makeRoomsVersion();

    Livewire::actingAs($founder)
        ->test(VersionRooms::class, ['version' => $version])
        ->assertOk();
});

test('mount allows a user holding Registration Manager on the Version', function () {
    $user = User::factory()->create();
    $version = makeRoomsVersion();
    grantVersionRole($user, $version, 'Registration Manager');

    Livewire::actingAs($user)
        ->test(VersionRooms::class, ['version' => $version])
        ->assertOk();
});

test('mount allows a user holding Co-Registration Manager on the Version', function () {
    $user = User::factory()->create();
    $version = makeRoomsVersion();
    grantVersionRole($user, $version, 'Co-Registration Manager');

    Livewire::actingAs($user)
        ->test(VersionRooms::class, ['version' => $version])
        ->assertOk();
});

test('mount aborts with 403 for an unrelated version-scoped role (e.g. Tab Room Manager)', function () {
    $user = User::factory()->create();
    $version = makeRoomsVersion();
    grantVersionRole($user, $version, 'Tab Room Manager');

    Livewire::actingAs($user)
        ->test(VersionRooms::class, ['version' => $version])
        ->assertStatus(403);
});

test('save creates a new room, assigns the next order_by, and syncs categories/voice parts', function () {
    $eventManager = User::factory()->create();
    $version = makeRoomsVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $voicePart = attachVoicePartToRoomsVersion($version);

    $category = ScoreCategory::create([
        'event_id' => $version->event_id,
        'version_id' => null,
        'description' => 'Scales',
        'order_by' => 1,
    ]);

    Livewire::actingAs($eventManager)
        ->test(VersionRooms::class, ['version' => $version])
        ->set('name', 'Soprano I')
        ->set('tolerance', '3')
        ->set('scoreCategoryIds', [$category->id])
        ->set('voicePartIds', [$voicePart->id])
        ->call('save')
        ->assertDispatched('toast-show', slots: ['text' => '"Soprano I" saved.']);

    $room = VersionRoom::where('version_id', $version->id)->first();

    expect($room)->not->toBeNull();
    expect($room->name)->toBe('Soprano I');
    expect($room->tolerance)->toBe(3);
    expect($room->order_by)->toBe(1);
    expect($room->scoreCategories->pluck('id')->all())->toBe([$category->id]);
    expect($room->voiceParts->pluck('id')->all())->toBe([$voicePart->id]);
});

test('save leaves tolerance null when left blank', function () {
    $eventManager = User::factory()->create();
    $version = makeRoomsVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');

    Livewire::actingAs($eventManager)
        ->test(VersionRooms::class, ['version' => $version])
        ->set('name', 'Alto I')
        ->set('tolerance', '')
        ->call('save');

    $room = VersionRoom::where('version_id', $version->id)->first();

    expect($room->tolerance)->toBeNull();
});

test('save rejects a score category id outside the Version\'s resolved rubric', function () {
    $eventManager = User::factory()->create();
    $version = makeRoomsVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $unrelatedCategory = ScoreCategory::create([
        'event_id' => Event::factory()->create(['organization_id' => Organization::factory()->create()->id])->id,
        'version_id' => null,
        'description' => 'Unrelated',
        'order_by' => 1,
    ]);

    Livewire::actingAs($eventManager)
        ->test(VersionRooms::class, ['version' => $version])
        ->set('name', 'Soprano I')
        ->set('scoreCategoryIds', [$unrelatedCategory->id])
        ->call('save')
        ->assertHasErrors(['scoreCategoryIds.0']);

    expect(VersionRoom::where('version_id', $version->id)->exists())->toBeFalse();
});

test('edit prefills the form fields including assigned categories and voice parts', function () {
    $eventManager = User::factory()->create();
    $version = makeRoomsVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $voicePart = attachVoicePartToRoomsVersion($version);

    $category = ScoreCategory::create([
        'event_id' => $version->event_id,
        'version_id' => null,
        'description' => 'Scales',
        'order_by' => 1,
    ]);

    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Tenor I', 'tolerance' => 2, 'order_by' => 1]);
    $room->scoreCategories()->sync([$category->id]);
    $room->voiceParts()->sync([$voicePart->id]);

    Livewire::actingAs($eventManager)
        ->test(VersionRooms::class, ['version' => $version])
        ->call('edit', $room->id)
        ->assertSet('editingId', $room->id)
        ->assertSet('name', 'Tenor I')
        ->assertSet('tolerance', '2')
        ->assertSet('scoreCategoryIds', [$category->id])
        ->assertSet('voicePartIds', [$voicePart->id]);
});

test('remove deletes the room row', function () {
    $eventManager = User::factory()->create();
    $version = makeRoomsVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Bass I', 'order_by' => 1]);

    Livewire::actingAs($eventManager)
        ->test(VersionRooms::class, ['version' => $version])
        ->call('remove', $room->id)
        ->assertDispatched('toast-show', slots: ['text' => '"Bass I" removed.']);

    expect(VersionRoom::find($room->id))->toBeNull();
});

test('saveOrder bulk-persists order_by from the numeric inputs', function () {
    $eventManager = User::factory()->create();
    $version = makeRoomsVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $first = VersionRoom::create(['version_id' => $version->id, 'name' => 'A', 'order_by' => 1]);
    $second = VersionRoom::create(['version_id' => $version->id, 'name' => 'B', 'order_by' => 2]);

    Livewire::actingAs($eventManager)
        ->test(VersionRooms::class, ['version' => $version])
        ->set("orderInputs.{$first->id}", 2)
        ->set("orderInputs.{$second->id}", 1)
        ->call('saveOrder')
        ->assertDispatched('toast-show', slots: ['text' => 'Room order saved.']);

    expect($first->fresh()->order_by)->toBe(2);
    expect($second->fresh()->order_by)->toBe(1);
});

test('reorderRooms moves the dragged item to its new position and resequences order_by', function () {
    $eventManager = User::factory()->create();
    $version = makeRoomsVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $first = VersionRoom::create(['version_id' => $version->id, 'name' => 'A', 'order_by' => 1]);
    $second = VersionRoom::create(['version_id' => $version->id, 'name' => 'B', 'order_by' => 2]);
    $third = VersionRoom::create(['version_id' => $version->id, 'name' => 'C', 'order_by' => 3]);

    Livewire::actingAs($eventManager)
        ->test(VersionRooms::class, ['version' => $version])
        ->call('reorderRooms', $first->id, 2);

    expect($second->fresh()->order_by)->toBe(1);
    expect($third->fresh()->order_by)->toBe(2);
    expect($first->fresh()->order_by)->toBe(3);
});

test('save creates a room_judge defaulting to Assigned status', function () {
    $eventManager = User::factory()->create();
    $version = makeRoomsVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $judgeUser = User::factory()->create(['email' => 'judge@example.com']);

    Livewire::actingAs($eventManager)
        ->test(VersionRooms::class, ['version' => $version])
        ->set('name', 'Soprano I')
        ->set('judges', [['id' => null, 'email' => 'judge@example.com', 'judge_type' => JudgeType::HeadJudge->value]])
        ->call('save');

    $room = VersionRoom::where('version_id', $version->id)->firstOrFail();
    $roomJudge = RoomJudge::where('room_id', $room->id)->first();

    expect($roomJudge)->not->toBeNull();
    expect($roomJudge->version_id)->toBe($version->id);
    expect($roomJudge->user_id)->toBe($judgeUser->id);
    expect($roomJudge->judge_type)->toBe(JudgeType::HeadJudge);
    expect($roomJudge->status)->toBe(JudgeStatus::Assigned);
});

test('save rejects an email that does not belong to any User', function () {
    $eventManager = User::factory()->create();
    $version = makeRoomsVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');

    Livewire::actingAs($eventManager)
        ->test(VersionRooms::class, ['version' => $version])
        ->set('name', 'Soprano I')
        ->set('judges', [['id' => null, 'email' => 'nobody@example.com', 'judge_type' => JudgeType::HeadJudge->value]])
        ->call('save')
        ->assertHasErrors(['judges.0.email']);

    expect(VersionRoom::where('version_id', $version->id)->exists())->toBeFalse();
});

test('edit prefills judges from existing room_judges rows', function () {
    $eventManager = User::factory()->create();
    $version = makeRoomsVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $judgeUser = User::factory()->create(['email' => 'judge@example.com']);

    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Alto I', 'order_by' => 1]);
    $roomJudge = RoomJudge::create([
        'version_id' => $version->id,
        'room_id' => $room->id,
        'user_id' => $judgeUser->id,
        'judge_type' => JudgeType::Monitor,
        'status' => JudgeStatus::Assigned,
    ]);

    Livewire::actingAs($eventManager)
        ->test(VersionRooms::class, ['version' => $version])
        ->call('edit', $room->id)
        ->assertSet('judges', [[
            'id' => $roomJudge->id,
            'email' => 'judge@example.com',
            'judge_type' => JudgeType::Monitor->value,
        ]]);
});

test('save updates an existing room_judge row in place rather than duplicating it', function () {
    $eventManager = User::factory()->create();
    $version = makeRoomsVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $judgeUser = User::factory()->create(['email' => 'judge@example.com']);

    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Alto I', 'order_by' => 1]);
    $roomJudge = RoomJudge::create([
        'version_id' => $version->id,
        'room_id' => $room->id,
        'user_id' => $judgeUser->id,
        'judge_type' => JudgeType::Judge1,
        'status' => JudgeStatus::Assigned,
    ]);

    Livewire::actingAs($eventManager)
        ->test(VersionRooms::class, ['version' => $version])
        ->call('edit', $room->id)
        ->set('judges.0.judge_type', JudgeType::Judge2->value)
        ->call('save');

    expect(RoomJudge::where('room_id', $room->id)->count())->toBe(1);
    expect($roomJudge->fresh()->judge_type)->toBe(JudgeType::Judge2);
});

test('save removes a room_judge row that is no longer submitted', function () {
    $eventManager = User::factory()->create();
    $version = makeRoomsVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');
    $judgeUser = User::factory()->create(['email' => 'judge@example.com']);

    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Alto I', 'order_by' => 1]);
    RoomJudge::create([
        'version_id' => $version->id,
        'room_id' => $room->id,
        'user_id' => $judgeUser->id,
        'judge_type' => JudgeType::Judge1,
        'status' => JudgeStatus::Assigned,
    ]);

    Livewire::actingAs($eventManager)
        ->test(VersionRooms::class, ['version' => $version])
        ->call('edit', $room->id)
        ->set('judges', [])
        ->call('save');

    expect(RoomJudge::where('room_id', $room->id)->count())->toBe(0);
});

test('addJudgeRow and removeJudgeRow manage the judges array', function () {
    $eventManager = User::factory()->create();
    $version = makeRoomsVersion();
    grantVersionRole($eventManager, $version, 'Event Manager');

    Livewire::actingAs($eventManager)
        ->test(VersionRooms::class, ['version' => $version])
        ->call('addJudgeRow')
        ->assertSet('judges', [['id' => null, 'email' => '', 'judge_type' => '']])
        ->call('removeJudgeRow', 0)
        ->assertSet('judges', []);
});
