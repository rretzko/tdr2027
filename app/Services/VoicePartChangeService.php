<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateUploadStatus;
use App\Enums\VoicePartRecordingDecision;
use App\Exceptions\VoicePartChangeRefusedException;
use App\Models\Candidate;
use App\Models\CandidateUploadFile;
use App\Models\Recording;
use App\Models\Score;
use App\Models\VoicePart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The one place a Candidate's voice part changes, so that their recordings
 * are always re-stamped, removed or marked as needing replacement. Callers
 * authorize, and recalculate status where their page does so.
 */
class VoicePartChangeService
{
    /**
     * @throws VoicePartChangeRefusedException
     */
    public function assertAllowed(Candidate $candidate, VoicePart $to): void
    {
        $candidate->loadMissing(['version', 'student']);
        $grade = $candidate->student->grade;

        if (! $candidate->version->availableVoicePartsForGrade($grade)->contains('id', $to->id)) {
            $gradeLabel = $grade !== null ? "grade {$grade}" : 'this student';

            throw new VoicePartChangeRefusedException("That voice part isn't offered for {$gradeLabel}.");
        }

        if (Score::where('candidate_id', $candidate->id)->exists()) {
            throw new VoicePartChangeRefusedException(
                'This candidate already has scores. Use Tab Room → Add/Edit Scores → Change Voice Part instead.'
            );
        }
    }

    /**
     * @param  int|null  $approverUserId  with Match: re-record the approval of approved recordings in this user's name
     * @param  bool  $override  Tab Room only: skip the grade and score rules
     *
     * @throws VoicePartChangeRefusedException
     */
    public function change(
        Candidate $candidate,
        VoicePart $to,
        VoicePartRecordingDecision $decision,
        ?int $approverUserId = null,
        bool $override = false,
    ): void {
        DB::transaction(function () use ($candidate, $to, $decision, $approverUserId, $override): void {
            $fromId = (int) Candidate::whereKey($candidate->id)->lockForUpdate()->value('voice_part_id');

            if ($fromId === $to->id) {
                return;
            }

            if (! $override) {
                $this->assertAllowed($candidate, $to);
            }

            match ($decision) {
                VoicePartRecordingDecision::Match => $this->restamp($candidate, $to, $approverUserId),
                VoicePartRecordingDecision::Remove => $this->removeRecordings($candidate),
                VoicePartRecordingDecision::Replace => $this->keepForReplacement($candidate, $fromId),
            };

            $candidate->update(['voice_part_id' => $to->id, 'voice_part_confirmed_at' => now()]);
        });

        $candidate->unsetRelation('voicePart');
    }

    private function restamp(Candidate $candidate, VoicePart $to, ?int $approverUserId): void
    {
        CandidateUploadFile::where('candidate_id', $candidate->id)->update(['voice_part_id' => $to->id]);
        Recording::where('candidate_id', $candidate->id)->update(['voice_part_id' => $to->id]);

        if ($approverUserId === null) {
            return;
        }

        CandidateUploadFile::where('candidate_id', $candidate->id)
            ->where('status', CandidateUploadStatus::Approved->value)
            ->update(['decided_at' => now(), 'decided_by_user_id' => $approverUserId]);
        Recording::where('candidate_id', $candidate->id)
            ->whereNotNull('approved_at')
            ->update(['approved_at' => now(), 'approved_by' => $approverUserId]);
    }

    private function removeRecordings(Candidate $candidate): void
    {
        $urls = CandidateUploadFile::where('candidate_id', $candidate->id)->pluck('url')->all();

        Recording::where('candidate_id', $candidate->id)->delete();
        CandidateUploadFile::where('candidate_id', $candidate->id)->delete();

        // Only once the change is final: a rolled-back change keeps its files.
        if ($urls !== []) {
            DB::afterCommit(fn () => Storage::disk('s3')->delete($urls));
        }
    }

    private function keepForReplacement(Candidate $candidate, int $fromId): void
    {
        $approved = CandidateUploadFile::where('candidate_id', $candidate->id)
            ->where('status', CandidateUploadStatus::Approved->value)
            ->exists();

        if ($approved || Recording::where('candidate_id', $candidate->id)->exists()) {
            throw new VoicePartChangeRefusedException(
                "Your teacher has already approved a recording, so your voice part can't be changed here. Please ask your teacher to change it."
            );
        }

        // An unstamped upload was made under the old part.
        CandidateUploadFile::where('candidate_id', $candidate->id)
            ->whereNull('voice_part_id')
            ->update(['voice_part_id' => $fromId]);
    }
}
