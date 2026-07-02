<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'version_id', 'registration', 'on_site_registration',
    'participation', 'epayment_surcharge', 'housing',
])]
class VersionFee extends Model
{
    /**
     * @return BelongsTo<Version, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    public function registrationInDollars(): float
    {
        return $this->registration / 100;
    }

    public function participationInDollars(): float
    {
        return $this->participation / 100;
    }
}
