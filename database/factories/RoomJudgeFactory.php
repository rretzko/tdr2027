<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\JudgeStatus;
use App\Enums\JudgeType;
use App\Models\RoomJudge;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionRoom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomJudge>
 */
class RoomJudgeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version_id' => Version::factory(),
            'room_id' => fn (array $attributes) => VersionRoom::create([
                'version_id' => $attributes['version_id'],
                'name' => 'Room 1',
                'order_by' => 1,
            ])->id,
            'user_id' => User::factory(),
            'judge_type' => JudgeType::Judge1,
            'status' => JudgeStatus::Assigned,
        ];
    }
}
