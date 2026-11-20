<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JudgeSurvey\JudgeAgain;
use App\Enums\JudgeSurvey\JudgeCheckpoint;
use App\Enums\JudgeSurvey\JudgeDeviceType;
use App\Enums\JudgeSurvey\JudgeFocus;
use App\Enums\JudgeSurvey\ListeningDevice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A judge's answers at one adjudication checkpoint (or a record that they
 * dismissed it). Reported only in aggregate: Event Managers never see which
 * judge said what. room_judge_id exists so a checkpoint is shown once.
 */
#[Fillable([
    'version_id', 'room_judge_id', 'checkpoint', 'completed_count', 'roster_count',
    'device_type', 'screen_width',
    'focus', 'obstacles', 'listening_device', 'overall_rating', 'judge_again', 'comment',
    'submitted_at', 'dismissed_at',
])]
class JudgeSurveyResponse extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checkpoint' => JudgeCheckpoint::class,
            'device_type' => JudgeDeviceType::class,
            'focus' => JudgeFocus::class,
            'obstacles' => 'array',
            'listening_device' => ListeningDevice::class,
            'judge_again' => JudgeAgain::class,
            'submitted_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Version, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    /**
     * @return BelongsTo<RoomJudge, $this>
     */
    public function roomJudge(): BelongsTo
    {
        return $this->belongsTo(RoomJudge::class);
    }
}
