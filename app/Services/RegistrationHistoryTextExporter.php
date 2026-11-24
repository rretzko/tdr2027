<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RegistrationHistory\RegistrationHistory;
use Carbon\CarbonInterface;

class RegistrationHistoryTextExporter
{
    /** Limits of the underlying records, shown on screen and in the file. */
    public const NOTES = [
        'All times are Eastern.',
        '"By" is the account that was used. Actions taken while impersonating someone are recorded under that person\'s account.',
        'A recording shows the time its slot was first uploaded; rejected recordings are deleted and replacement times are not kept.',
        'Only the latest obligation decision is kept, and removed signatures leave no record.',
    ];

    public function filename(RegistrationHistory $history, CarbonInterface $now): string
    {
        return sprintf(
            'registration-history-v%d-%s-%s.txt',
            $history->versionId,
            $history->fileKey,
            $this->local($now)->format('Ymd'),
        );
    }

    public function render(RegistrationHistory $history, CarbonInterface $now): string
    {
        $lines = [
            'REGISTRATION HISTORY - '.$this->clean($history->versionName),
            $history->kind.': '.$this->clean($history->name),
        ];

        foreach ($history->facts as $label => $value) {
            $lines[] = $label.': '.$this->clean($value);
        }

        $lines[] = 'Generated: '.$this->local($now)->format('M j, Y g:i A').' ET';

        if ($history->checklist !== []) {
            $lines[] = '';
            $lines[] = 'REGISTRATION CHECKLIST';

            foreach ($history->checklist as $item) {
                $lines[] = ($item['done'] ? '[x] ' : '[ ] ').$this->clean($item['label']);
            }
        }

        if ($history->incomplete !== []) {
            $lines[] = '';
            $lines[] = 'INCOMPLETE STUDENTS';

            foreach ($history->incomplete as $row) {
                $lines[] = $this->clean("{$row['student']} ({$row['status']}): ".(implode('; ', $row['missing']) ?: 'checklist complete'));
            }
        }

        $lines[] = '';
        $lines[] = 'TIMELINE';

        foreach ($history->entries as $entry) {
            $line = $entry->displayAt().'  '.($entry->isDeadline ? '--- ' : '')
                .($entry->student !== null ? $entry->student.': ' : '').$entry->description;

            if ($entry->actor !== null) {
                $line .= '  [by '.$entry->actor.']';
            }

            $lines[] = $this->clean($line);
        }

        $lines[] = '';
        $lines[] = 'NOTES';

        foreach (self::NOTES as $note) {
            $lines[] = '- '.$note;
        }

        // CRLF so it opens cleanly in Windows Notepad.
        return implode("\r\n", $lines)."\r\n";
    }

    /** Names and file names are user-entered: keep each entry on one line. */
    private function clean(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));
    }

    private function local(CarbonInterface $now): CarbonInterface
    {
        return $now->copy()->timezone((string) config('app.display_timezone'));
    }
}
