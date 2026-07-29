<?php

declare(strict_types=1);

use App\Enums\JudgeStatus;
use App\Enums\JudgeType;
use App\Models\Candidate;
use App\Models\RoomJudge;
use App\Models\Score;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionRoom;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('score is mass-assignable and resolves every belongsTo relation', function () {
    actingAs(User::factory()->create());

    $version = Version::factory()->create();
    $candidate = Candidate::factory()->create(['version_id' => $version->id]);

    $category = ScoreCategory::create([
        'event_id' => $version->event_id,
        'version_id' => null,
        'description' => 'Scales',
        'order_by' => 1,
    ]);

    $factor = ScoreFactor::create([
        'event_id' => $version->event_id,
        'version_id' => null,
        'score_category_id' => $category->id,
        'description' => 'High Scale',
        'abbreviation' => 'HS',
        'best' => 5,
        'worst' => 1,
        'interval_by' => 1,
        'multiplier' => 1,
        'tolerance' => null,
        'order_by' => 1,
    ]);

    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Soprano I', 'order_by' => 1]);

    $roomJudge = RoomJudge::create([
        'version_id' => $version->id,
        'room_id' => $room->id,
        'user_id' => User::factory()->create()->id,
        'judge_type' => JudgeType::HeadJudge,
        'status' => JudgeStatus::Assigned,
    ]);

    $score = Score::create([
        'version_id' => $version->id,
        'candidate_id' => $candidate->id,
        'student_id' => $candidate->student_id,
        'school_id' => $candidate->school_id,
        'score_category_id' => $category->id,
        'score_category_order_by' => $category->order_by,
        'score_factor_id' => $factor->id,
        'score_factor_order_by' => $factor->order_by,
        'judge_id' => $roomJudge->id,
        'judge_order_by' => 1,
        'voice_part_id' => $candidate->voice_part_id,
        'voice_part_order_by' => 1,
        'score' => 4,
    ]);

    expect($score->version->id)->toBe($version->id);
    expect($score->candidate->id)->toBe($candidate->id);
    expect($score->student->id)->toBe($candidate->student_id);
    expect($score->school->id)->toBe($candidate->school_id);
    expect($score->scoreCategory->id)->toBe($category->id);
    expect($score->scoreFactor->id)->toBe($factor->id);
    expect($score->judge->id)->toBe($roomJudge->id);
    expect($score->voicePart->id)->toBe($candidate->voice_part_id);
    expect($score->score)->toBe(4);
});

test('deleting a candidate cascades to delete its scores', function () {
    actingAs(User::factory()->create());

    $version = Version::factory()->create();
    $candidate = Candidate::factory()->create(['version_id' => $version->id]);

    $category = ScoreCategory::create([
        'event_id' => $version->event_id,
        'version_id' => null,
        'description' => 'Scales',
        'order_by' => 1,
    ]);

    $factor = ScoreFactor::create([
        'event_id' => $version->event_id,
        'version_id' => null,
        'score_category_id' => $category->id,
        'description' => 'High Scale',
        'abbreviation' => 'HS',
        'best' => 5,
        'worst' => 1,
        'interval_by' => 1,
        'multiplier' => 1,
        'tolerance' => null,
        'order_by' => 1,
    ]);

    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Soprano I', 'order_by' => 1]);

    $roomJudge = RoomJudge::create([
        'version_id' => $version->id,
        'room_id' => $room->id,
        'user_id' => User::factory()->create()->id,
        'judge_type' => JudgeType::HeadJudge,
        'status' => JudgeStatus::Assigned,
    ]);

    $voicePart = VoicePart::factory()->create();

    $score = Score::create([
        'version_id' => $version->id,
        'candidate_id' => $candidate->id,
        'student_id' => $candidate->student_id,
        'school_id' => $candidate->school_id,
        'score_category_id' => $category->id,
        'score_category_order_by' => 1,
        'score_factor_id' => $factor->id,
        'score_factor_order_by' => 1,
        'judge_id' => $roomJudge->id,
        'judge_order_by' => 1,
        'voice_part_id' => $voicePart->id,
        'voice_part_order_by' => 1,
        'score' => 3,
    ]);

    $candidate->delete();

    expect(Score::find($score->id))->toBeNull();
});
