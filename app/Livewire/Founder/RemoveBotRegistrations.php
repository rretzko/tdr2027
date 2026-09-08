<?php

declare(strict_types=1);

namespace App\Livewire\Founder;

use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Finds registrations that predate the honeypot/rate-limit guard
 * (GuardsAgainstBotRegistration) and never went anywhere: never verified,
 * never logged in, never visited a page, and show zero engagement anywhere
 * in the system. Nothing about a registration is flagged as a bot at submit
 * time — bots are simply blocked from completing the form now — so this
 * page can only recognize old bot accounts by their total lack of
 * follow-through.
 *
 * "No current school link" alone is NOT used as a signal — a real teacher
 * who left teaching, or a student who transferred out, can end up with zero
 * school_teacher/school_student rows despite years of real history. Instead
 * this requires zero rows in every table that only gets a row through real
 * use (candidates, memberships, event/version invitations, emergency
 * contacts, etc.) — see suspects() for the full list. Getting this wrong in
 * either direction is asymmetric: leaving a real bot account behind is a
 * minor annoyance, deleting a real person's account and history is not.
 *
 * @property-read array<int, array{
 *     user_id: int,
 *     type: string,
 *     name: string,
 *     email: string,
 *     created_at: string,
 *     reasons: list<string>,
 * }> $suspects
 */
#[Layout('components.layouts.app')]
class RemoveBotRegistrations extends Component
{
    /**
     * Registrations younger than this are left alone even if they look
     * suspicious so far — a genuine new user hasn't had time yet to verify
     * their email or join a school.
     */
    private const MIN_ACCOUNT_AGE_DAYS = 2;

    /** @var list<int> */
    public array $selected = [];

    /**
     * @return array<int, array{
     *     user_id: int,
     *     type: string,
     *     name: string,
     *     email: string,
     *     created_at: string,
     *     reasons: list<string>,
     * }>
     */
    #[Computed]
    public function suspects(): array
    {
        $users = $this->candidateQuery()->orderBy('created_at')->get();

        return $users->map(function (User $user): array {
            $reasons = [
                'Email never verified',
                'No login recorded',
                'No page visits recorded',
                'No activity anywhere in the system',
            ];

            return [
                'user_id' => $user->id,
                'type' => $user->student !== null ? 'Student' : 'Teacher',
                'name' => trim("{$user->first_name} {$user->last_name}"),
                'email' => $user->email,
                'created_at' => $user->created_at->format('M j, Y'),
                'reasons' => $reasons,
            ];
        })->all();
    }

    /**
     * @return Builder<User>
     */
    private function candidateQuery(): Builder
    {
        $cutoff = now()->subDays(self::MIN_ACCOUNT_AGE_DAYS);

        return User::query()
            ->where('email', '!=', 'rick@mfrholdings.com')
            ->where('email_unverifiable', false)
            ->whereNull('email_verified_at')
            ->where('created_at', '<=', $cutoff)
            ->whereDoesntHave('loginEvents')
            ->whereDoesntHave('pageVisits')
            ->whereDoesntHave('socialAccounts')
            ->where(function (Builder $outer): void {
                $outer->whereHas('student', function (Builder $q): void {
                    $q->whereDoesntHave('schools')
                        ->whereDoesntHave('teachers')
                        ->whereDoesntHave('candidates')
                        ->whereDoesntHave('emergencyContacts')
                        ->whereDoesntHave('homeAddress');
                })->orWhereHas('teacher', function (Builder $q): void {
                    $q->whereDoesntHave('schools')
                        ->whereDoesntHave('students')
                        ->whereDoesntHave('candidates')
                        ->whereDoesntHave('teacherSupervisors')
                        ->whereDoesntHave('eventInvitationRequests')
                        ->whereDoesntHave('memberships')
                        ->whereDoesntHave('versionInvitations')
                        ->whereDoesntHave('versionInvitationRequests')
                        ->whereDoesntHave('versionTeacherPackets')
                        ->whereDoesntHave('coTeacherGrantsGiven')
                        ->whereDoesntHave('coTeacherGrantsReceived');
                });
            })
            ->with(['student', 'teacher']);
    }

    public function selectAll(): void
    {
        $this->selected = array_column($this->suspects(), 'user_id');
    }

    public function deselectAll(): void
    {
        $this->selected = [];
    }

    public function refresh(): void
    {
        unset($this->suspects);
        $this->selected = [];
    }

    public function confirmRemoval(): void
    {
        if ($this->selected === []) {
            return;
        }

        $this->modal('remove-confirm')->show();
    }

    public function removeSelected(): void
    {
        if ($this->selected === []) {
            return;
        }

        // Re-run the exact detection query scoped to the selection, rather
        // than trusting the ids as-is — this is what protects against a
        // stale selection (something changed between page load and
        // confirm) or a future gap in the heuristic ever deleting a real
        // account. Anything selected that no longer qualifies is silently
        // skipped instead of deleted.
        $users = $this->candidateQuery()->whereIn('id', $this->selected)->get();
        $count = $users->count();
        $skipped = count($this->selected) - $count;

        DB::transaction(function () use ($users): void {
            foreach ($users as $user) {
                $this->deleteUser($user);
            }
        });

        $this->modal('remove-confirm')->close();
        $this->selected = [];
        unset($this->suspects);

        $message = "{$count} bot registration(s) removed.";
        if ($skipped > 0) {
            $message .= " {$skipped} skipped — no longer matched the criteria.";
        }

        Flux::toast(variant: 'success', text: $message);
    }

    private function deleteUser(User $user): void
    {
        $student = $user->student;
        $teacher = $user->teacher;

        if ($student instanceof Student) {
            $student->homeAddress()->delete();
            $student->emergencyContacts()->delete();
            $student->schools()->detach();
            $student->teachers()->detach();
            $student->delete();
        }

        if ($teacher instanceof Teacher) {
            $teacher->schools()->detach();
            $teacher->students()->detach();
            $teacher->teacherSupervisors()->delete();
            $teacher->eventInvitationRequests()->delete();
            $teacher->memberships()->delete();
            $teacher->delete();
        }

        $user->phones()->delete();
        $user->socialAccounts()->delete();
        $user->pageVisits()->delete();
        $user->delete();
    }

    public function render(): View
    {
        return view('livewire.founder.remove-bot-registrations');
    }
}
