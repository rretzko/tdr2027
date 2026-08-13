<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VersionEpaymentConfigFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-Version accept-electronic-payment flags only. The vendor credential
 * itself lives on EventEpaymentConfig — see that model's docblock for why
 * this is split (epayment-integration.md §1.2/§5 item 8). Replaces
 * EpaymentCredential — that model/table is cut over and dropped once
 * App\Livewire\Registrations\CandidateDetail and VersionEdit read from these
 * two instead (§4 steps 5/9).
 */
#[Fillable(['version_id', 'epayment_student', 'epayment_teacher'])]
class VersionEpaymentConfig extends Model
{
    /** @use HasFactory<VersionEpaymentConfigFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'epayment_student' => 'boolean',
            'epayment_teacher' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Version, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }
}
