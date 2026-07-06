<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CandidateStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['candidate_id', 'from_status', 'to_status', 'user_id', 'notes'])]
class CandidateStatusHistory extends Model
{
    protected $table = 'candidate_status_history';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => CandidateStatus::class,
            'to_status' => CandidateStatus::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
