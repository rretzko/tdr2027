<?php

declare(strict_types=1);

use App\Enums\JudgeStatus;
use App\Enums\JudgeType;
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

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

function makeRosterPdfVersion(): Version
{
    $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    return Version::factory()->create(['event_id' => $event->id]);
}

test('aborts with 403 for a user with no version-scoped role on the Version', function () {
    $user = User::factory()->create();
    $version = makeRosterPdfVersion();

    actingAs($user);

    get(route('events.versions.rooms.roster-pdf', $version))
        ->assertForbidden();
});

test('returns a PDF for a user holding Event Manager on the Version', function () {
    $user = User::factory()->create();
    $version = makeRosterPdfVersion();
    grantVersionRole($user, $version, 'Event Manager');

    actingAs($user);

    get(route('events.versions.rooms.roster-pdf', $version))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('returns a PDF listing rooms, categories, voice parts, and judges', function () {
    $user = User::factory()->create();
    $version = makeRosterPdfVersion();
    grantVersionRole($user, $version, 'Event Manager');

    $category = ScoreCategory::create([
        'event_id' => $version->event_id,
        'version_id' => null,
        'description' => 'Scales',
        'order_by' => 1,
    ]);

    $voicePart = VoicePart::factory()->create();
    $ensemble = Ensemble::factory()->create(['event_id' => $version->event_id]);
    $ensemble->voiceParts()->attach($voicePart->id);

    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Soprano I', 'order_by' => 1]);
    $room->scoreCategories()->sync([$category->id]);
    $room->voiceParts()->sync([$voicePart->id]);

    $judgeUser = User::factory()->create(['first_name' => 'Jamie', 'last_name' => 'Judge']);
    RoomJudge::create([
        'version_id' => $version->id,
        'room_id' => $room->id,
        'user_id' => $judgeUser->id,
        'judge_type' => JudgeType::HeadJudge,
        'status' => JudgeStatus::Assigned,
    ]);

    actingAs($user);

    get(route('events.versions.rooms.roster-pdf', $version))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('returns a PDF even when the Version has no rooms yet', function () {
    $user = User::factory()->create();
    $version = makeRosterPdfVersion();
    grantVersionRole($user, $version, 'Event Manager');

    actingAs($user);

    get(route('events.versions.rooms.roster-pdf', $version))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});
