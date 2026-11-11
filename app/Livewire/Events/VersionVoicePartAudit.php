<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Livewire\Shared\AbstractVoicePartAudit;
use App\Models\User;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * The Voice Part Audit tab on Web Registration, bound to one Version.
 * Open to everyone with Web Registration access plus Registration /
 * Co-Registration Managers (VersionRoleAssignmentService::canAuditVoiceParts());
 * Co-Registration Managers are limited to their counties
 * (voicePartAuditCountyIds()).
 */
class VersionVoicePartAudit extends AbstractVoicePartAudit
{
    #[Locked]
    public ?int $versionId = null;

    public function mount(Version $version): void
    {
        $this->versionId = $version->id;
        $this->authorizeAudit();
    }

    protected function authorizeAudit(): void
    {
        $version = Version::query()->with('event')->find($this->versionId);
        $user = Auth::user();

        abort_unless(
            $version !== null && $user instanceof User && app(VersionRoleAssignmentService::class)->canAuditVoiceParts($user, $version),
            403,
        );
    }

    protected function countyIds(Version $version): ?array
    {
        $user = Auth::user();

        return $user instanceof User
            ? app(VersionRoleAssignmentService::class)->voicePartAuditCountyIds($user, $version)
            : [];
    }

    protected function standalone(): bool
    {
        return false;
    }
}
