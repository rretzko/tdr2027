<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Candidate;
use App\Models\CandidateStatusHistory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CandidateObserver
{
    public function creating(Candidate $candidate): void
    {
        $this->assignId($candidate);
        $this->assignProgramName($candidate);
    }

    public function created(Candidate $candidate): void
    {
        CandidateStatusHistory::create([
            'candidate_id' => $candidate->id,
            'from_status' => null,
            'to_status' => $candidate->getRawOriginal('status'),
            'user_id' => Auth::id(),
            'notes' => null,
        ]);
    }

    public function updating(Candidate $candidate): void
    {
        if (! $candidate->isDirty('status')) {
            return;
        }

        $fromRaw = $candidate->getOriginal('status');

        CandidateStatusHistory::create([
            'candidate_id' => $candidate->id,
            'from_status' => is_string($fromRaw) ? $fromRaw : null,
            'to_status' => $candidate->getRawOriginal('status'),
            'user_id' => Auth::id(),
            'notes' => null,
        ]);
    }

    private function assignId(Candidate $candidate): void
    {
        $versionId = $candidate->version_id;
        $maxAttempts = 20;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $suffix = random_int(1000, 9999);
            $id = (int) ($versionId.$suffix);
            $ref = $versionId.'-'.$suffix;

            $exists = DB::table('candidates')
                ->where('id', $id)
                ->exists();

            if (! $exists) {
                $candidate->id = $id;
                $candidate->ref = $ref;

                return;
            }
        }

        throw new \RuntimeException("Could not generate a unique candidate id for version {$versionId} after {$maxAttempts} attempts.");
    }

    private function assignProgramName(Candidate $candidate): void
    {
        if ($candidate->program_name !== '') {
            return;
        }

        $student = $candidate->student()->with('user')->first();

        if ($student?->user !== null) {
            $candidate->program_name = trim($student->user->first_name.' '.$student->user->last_name);
        }
    }
}
