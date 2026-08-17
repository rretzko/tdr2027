<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VersionMailToAddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Registration Manager or Co-Registration Manager's physical mailing
 * address for one Version — see event-version-orientation.md §5.12. Edited
 * inline on VersionEdit's Roles tab (Registration Manager) or
 * VersionCoRegistrationManagers (each Co-Registration Manager), resolved for
 * the Estimate Form's Mail-To page via App\Services\MailToAddressResolver.
 */
#[Fillable([
    'version_id', 'user_id', 'recipient_name', 'organization_line',
    'address_line1', 'address_line2', 'city', 'geostate_id', 'zip',
])]
class VersionMailToAddress extends Model
{
    /** @use HasFactory<VersionMailToAddressFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Version, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Geostate, $this>
     */
    public function geostate(): BelongsTo
    {
        return $this->belongsTo(Geostate::class);
    }
}
