<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeedbackRequestType;
use App\Enums\FeedbackStatus;
use Database\Factories\FeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['user_id', 'from_page', 'request_type', 'request', 'file_path', 'is_private', 'status'])]
class Feedback extends Model
{
    /** @use HasFactory<FeedbackFactory> */
    use HasFactory;

    protected $table = 'feedback';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_type' => FeedbackRequestType::class,
            'status' => FeedbackStatus::class,
            'is_private' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fileUrl(): ?string
    {
        if ($this->file_path === null) {
            return null;
        }

        return Storage::disk('s3')->temporaryUrl($this->file_path, now()->addMinutes(30));
    }
}
