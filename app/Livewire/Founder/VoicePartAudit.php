<?php

declare(strict_types=1);

namespace App\Livewire\Founder;

use App\Livewire\Shared\AbstractVoicePartAudit;
use App\Models\Version;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;

/**
 * Founder page for the Voice Part ↔ Recording audit: any Version, chosen with
 * a picker, every county. Event-side access is the Voice Part Audit tab on
 * Web Registration (Events\VersionVoicePartAudit).
 */
#[Layout('components.layouts.app')]
class VoicePartAudit extends AbstractVoicePartAudit
{
    #[Url(as: 'version')]
    public ?int $versionId = null;

    public function mount(): void
    {
        $this->authorizeAudit();
    }

    protected function authorizeAudit(): void
    {
        abort_unless(Auth::user()?->isFounder() === true, 403);
    }

    protected function countyIds(Version $version): ?array
    {
        return null;
    }

    protected function standalone(): bool
    {
        return true;
    }
}
