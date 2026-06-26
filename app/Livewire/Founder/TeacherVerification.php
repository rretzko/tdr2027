<?php

declare(strict_types=1);

namespace App\Livewire\Founder;

use App\Mail\SchoolEmailVerificationMail;
use App\Models\Pivots\SchoolTeacher;
use App\Support\ReplacedTeacherStudentTransfer;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL as UrlFacade;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class TeacherVerification extends Component
{
    public bool $pendingOnly = false;

    /**
     * @return Collection<int, SchoolTeacher>
     */
    public function pivots(): Collection
    {
        $query = SchoolTeacher::with(['teacher.user', 'school']);

        if ($this->pendingOnly) {
            $query->whereNotNull('school_email')->whereNull('verified_at');
        }

        return $query->get()->sortBy(function (SchoolTeacher $p): string {
            $rank = match (true) {
                filled($p->school_email) && blank($p->verified_at) => '0',
                blank($p->school_email) => '1',
                default => '2',
            };

            return $rank.'|'.($p->teacher->user->last_name ?? '').'|'.($p->teacher->user->first_name ?? '');
        })->values();
    }

    public function verifyTeacher(int $id): void
    {
        $pivot = SchoolTeacher::with(['teacher.user', 'school'])->findOrFail($id);

        $pivot->update(['verified_at' => now()]);
        ReplacedTeacherStudentTransfer::transfer($pivot);

        $name = $pivot->teacher->user->first_name.' '.$pivot->teacher->user->last_name;
        Flux::toast(text: "{$name} manually verified at {$pivot->school->name}.", variant: 'success');
    }

    public function sendVerificationEmail(int $id): void
    {
        $pivot = SchoolTeacher::with(['teacher.user', 'school'])->findOrFail($id);

        if (blank($pivot->school_email) || filled($pivot->verified_at)) {
            return;
        }

        $url = UrlFacade::temporarySignedRoute(
            'school-email.verify',
            now()->addDays(3),
            ['schoolTeacher' => $pivot->id, 'email' => $pivot->school_email],
        );

        Mail::to($pivot->school_email)->send(new SchoolEmailVerificationMail($pivot, $url));

        $name = $pivot->teacher->user->first_name.' '.$pivot->teacher->user->last_name;
        Flux::toast(text: "Verification email sent to {$name} at {$pivot->school_email}.", variant: 'success');
    }

    public function confirmAnnualReset(): void
    {
        $this->modal('confirm-annual-reset')->show();
    }

    public function resetAllAndSendEmails(): void
    {
        $pivots = SchoolTeacher::with(['teacher.user', 'school'])
            ->whereNotNull('school_email')
            ->get();

        SchoolTeacher::whereNotNull('school_email')->update(['verified_at' => null]);

        $count = 0;
        foreach ($pivots as $pivot) {
            $url = UrlFacade::temporarySignedRoute(
                'school-email.verify',
                now()->addDays(30),
                ['schoolTeacher' => $pivot->id, 'email' => $pivot->school_email],
            );

            Mail::to($pivot->school_email)->queue(new SchoolEmailVerificationMail($pivot, $url));
            $count++;
        }

        $this->modal('confirm-annual-reset')->close();
        Flux::toast(text: "Annual reset complete. Verification emails queued for {$count} teacher(s).", variant: 'success');
    }

    public function render(): View
    {
        return view('livewire.founder.teacher-verification', [
            'pivots' => $this->pivots(),
        ]);
    }
}
