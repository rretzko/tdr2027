<?php

declare(strict_types=1);

namespace App\Livewire\Registrations;

use App\Enums\ObligationDecision;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VersionObligation;
use App\Models\VersionObligationResponse;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class VersionObligations extends Component
{
    public Version $version;

    public VersionInvitation $invitation;

    public function mount(Version $version): void
    {
        $invitation = VersionInvitation::where('version_id', $version->id)
            ->where('teacher_id', $this->teacher()->id)
            ->first();

        abort_if($invitation === null, 404);

        $this->version = $version;
        $this->invitation = $invitation;
    }

    public function accept(): void
    {
        $this->respond(ObligationDecision::Accepted);

        Flux::toast(text: 'Obligations accepted.', variant: 'success');
    }

    public function reject(): void
    {
        $this->respond(ObligationDecision::Rejected);

        Flux::toast(text: 'Obligations rejected.', variant: 'warning');
    }

    public function render(): View
    {
        $obligation = $this->publishedObligation();

        return view('livewire.registrations.version-obligations', [
            'obligation' => $obligation,
            'body' => $obligation !== null ? VersionObligation::mergeTokens($obligation->body, $this->version) : null,
            'response' => $this->invitation->obligationResponse()->first(),
        ]);
    }

    private function respond(ObligationDecision $decision): void
    {
        $obligation = $this->publishedObligation();

        abort_if($obligation === null, 404);

        VersionObligationResponse::updateOrCreate(
            ['version_invitation_id' => $this->invitation->id],
            [
                'version_obligation_id' => $obligation->id,
                'decision' => $decision->value,
                'decided_at' => now(),
                'obligation_snapshot' => VersionObligation::mergeTokens($obligation->body, $this->version),
            ],
        );
    }

    private function publishedObligation(): ?VersionObligation
    {
        $obligation = $this->version->obligation;

        return $obligation?->isPublished() ? $obligation : null;
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }
}
