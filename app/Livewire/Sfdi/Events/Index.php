<?php

declare(strict_types=1);

namespace App\Livewire\Sfdi\Events;

use App\Enums\EventStatus;
use App\Models\Candidate;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Student-facing "My Events" list (studentfolder-module.md §5.1) — a
 * read-only view of this student's own Candidate rows across active
 * Versions. Unlike the teacher-side Registrations\Index, there is no
 * "eligible to request" bucket: a student never requests an invitation
 * (§0 — direct school/teacher join, no approval step), so a Candidate row
 * already exists the moment AutoEnrollmentService has run at school-join
 * time. This page surfaces those rows; it creates nothing.
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    public function mount(): void
    {
        abort_if($this->student() === null, 403);
    }

    public function render(): View
    {
        /** @var Collection<int, Candidate> $candidates */
        $candidates = Candidate::where('student_id', $this->student()->id)
            ->whereHas('version', fn ($query) => $query->where('status', EventStatus::Active->value))
            ->with(['version.event', 'teacher.user'])
            ->get();

        $sorted = $candidates->all();

        // usort, not Collection::sortBy() with a multi-criteria array — a
        // 1-arg callback there silently misorders (PHPStan-quirks memory).
        usort(
            $sorted,
            fn (Candidate $a, Candidate $b): int => [$a->version->event->name, $a->version->name]
                <=> [$b->version->event->name, $b->version->name],
        );

        return view('livewire.sfdi.events.index', [
            'candidates' => new Collection($sorted),
        ]);
    }

    private function student(): ?Student
    {
        return Auth::user()->student;
    }
}
