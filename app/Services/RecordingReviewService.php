<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateUploadStatus;
use App\Models\CandidateUploadFile;
use App\Models\VersionUploadFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A rudimentary, non-blocking assist for the "wrong slot" mistake teachers
 * previously had to catch entirely by eye (e.g. a scales recording uploaded
 * into the Solo slot) — two cheap heuristics run at upload time, and either
 * one flags the file for the teacher's own review. Never rejects or blocks
 * approval on its own; a false flag just costs a teacher a second look.
 */
class RecordingReviewService
{
    /**
     * Minimum number of prior *approved* durations for this exact slot
     * before the duration check applies at all — below this, there isn't
     * enough of a baseline to call anything an outlier.
     */
    private const MIN_SAMPLE_SIZE = 3;

    /**
     * How many standard deviations from the mean counts as unusual.
     */
    private const FLAG_STD_DEVS = 1.0;

    /**
     * A floor under the computed standard deviation, so a slot whose prior
     * uploads happen to be nearly identical in length doesn't flag every
     * new upload over a couple of seconds of natural encoding jitter.
     */
    private const MIN_TOLERANCE_SECONDS = 3;

    /**
     * Runs both heuristics for one uploaded file and returns everything
     * `CandidateDetail::saveRecording()` needs to persist.
     *
     * @param  Collection<int, VersionUploadFile>  $allSlotsInVersion
     * @return array{original_filename: string, duration_seconds: int|null, flagged_at: Carbon|null, flag_reason: string|null}
     */
    public function evaluate(UploadedFile $file, VersionUploadFile $targetSlot, Collection $allSlotsInVersion): array
    {
        $originalFilename = $file->getClientOriginalName();
        $durationSeconds = $this->extractDurationSeconds($file->getRealPath());

        $reasons = array_filter([
            $this->filenameFlagReason($originalFilename, $targetSlot, $allSlotsInVersion),
            $durationSeconds !== null ? $this->durationFlagReason($durationSeconds, $targetSlot) : null,
        ]);

        return [
            'original_filename' => $originalFilename,
            'duration_seconds' => $durationSeconds,
            'flagged_at' => $reasons === [] ? null : Carbon::now(),
            'flag_reason' => $reasons === [] ? null : implode(' ', $reasons),
        ];
    }

    /**
     * Reads embedded audio/video metadata via getID3 (pure PHP, no ffmpeg
     * binary required) — returns null rather than throwing on anything it
     * can't parse, since a failed extraction should just skip the duration
     * check, not break the upload.
     */
    public function extractDurationSeconds(string|false $absolutePath): ?int
    {
        if ($absolutePath === false || $absolutePath === '' || ! is_readable($absolutePath)) {
            return null;
        }

        $info = (new \getID3)->analyze($absolutePath);

        if (! isset($info['playtime_seconds']) || ! is_numeric($info['playtime_seconds'])) {
            return null;
        }

        return (int) round((float) $info['playtime_seconds']);
    }

    /**
     * Flags when the filename's words match another slot in this Version
     * but not the one it's actually being uploaded to — e.g. "Alto on
     * scales.mp3" uploaded to the Solo slot, when a Scales slot also
     * exists. A filename that mentions no known slot at all (most device
     * default names — "New Recording 3.m4a", "IMG_4821.mov") has no
     * signal either way and is never flagged by this check.
     *
     * @param  Collection<int, VersionUploadFile>  $allSlotsInVersion
     */
    public function filenameFlagReason(string $originalFilename, VersionUploadFile $targetSlot, Collection $allSlotsInVersion): ?string
    {
        $filenameWithoutExtension = preg_replace('/\.[a-z0-9]{2,4}$/i', '', $originalFilename) ?? $originalFilename;
        $filenameWords = $this->normalizeWords($filenameWithoutExtension);

        if ($filenameWords === [] || $this->filenameMentionsSlot($filenameWords, $targetSlot->name)) {
            return null;
        }

        $mentionedOtherSlots = $allSlotsInVersion
            ->reject(fn (VersionUploadFile $slot): bool => $slot->id === $targetSlot->id)
            ->filter(fn (VersionUploadFile $slot): bool => $this->filenameMentionsSlot($filenameWords, $slot->name));

        if ($mentionedOtherSlots->isEmpty()) {
            return null;
        }

        $names = $mentionedOtherSlots->pluck('name')->implode('" or "');

        return "The file name (\"{$originalFilename}\") mentions \"{$names}\" rather than \"{$targetSlot->name}\" — double-check it's in the right slot.";
    }

    /**
     * Flags when the file's duration falls outside ±1 standard deviation of
     * the durations of every *approved* upload already on file for this
     * exact slot — pending/rejected uploads aren't trustworthy enough to
     * anchor the baseline. Silently skips (no flag either way) until at
     * least MIN_SAMPLE_SIZE approved uploads exist for the slot.
     */
    public function durationFlagReason(int $durationSeconds, VersionUploadFile $slot): ?string
    {
        $priorDurations = CandidateUploadFile::where('version_upload_file_id', $slot->id)
            ->where('status', CandidateUploadStatus::Approved->value)
            ->whereNotNull('duration_seconds')
            ->pluck('duration_seconds');

        if ($priorDurations->count() < self::MIN_SAMPLE_SIZE) {
            return null;
        }

        $mean = (float) $priorDurations->avg();
        $variance = $priorDurations->reduce(
            fn (float $carry, int $d): float => $carry + ($d - $mean) ** 2,
            0.0,
        ) / $priorDurations->count();
        $stdDev = max(sqrt($variance), self::MIN_TOLERANCE_SECONDS);

        $lower = $mean - self::FLAG_STD_DEVS * $stdDev;
        $upper = $mean + self::FLAG_STD_DEVS * $stdDev;

        if ($durationSeconds >= $lower && $durationSeconds <= $upper) {
            return null;
        }

        return sprintf(
            'Duration (%s) is unusual for "%s" — typical uploads for this slot run %s\u{2013}%s.',
            $this->formatDuration($durationSeconds),
            $slot->name,
            $this->formatDuration((int) round(max($lower, 0.0))),
            $this->formatDuration((int) round($upper)),
        );
    }

    /**
     * @return list<string>
     */
    private function normalizeWords(string $text): array
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', ' ', $text));

        return array_values(array_filter(explode(' ', trim($normalized))));
    }

    /**
     * @param  list<string>  $filenameWords
     */
    private function filenameMentionsSlot(array $filenameWords, string $slotName): bool
    {
        $slotWords = $this->normalizeWords($slotName);

        if ($slotWords === []) {
            return false;
        }

        foreach ($slotWords as $word) {
            if (! in_array($word, $filenameWords, true)) {
                return false;
            }
        }

        return true;
    }

    private function formatDuration(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
