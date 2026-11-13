<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Version;
use App\Services\VersionDataDownloadService;
use App\Services\VersionRoleAssignmentService;
use App\Support\Reports\CsvExport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports → Downloads: one CSV per topic of the Version's owned data. Event
 * Manager (and Founder) only — see canDownloadVersionData().
 */
class VersionDataDownloadController extends Controller
{
    public function __invoke(Version $version, string $topic, VersionRoleAssignmentService $roles, VersionDataDownloadService $downloads): StreamedResponse
    {
        abort_unless($roles->canDownloadVersionData(Auth::user(), $version), 403);

        $filename = Str::slug($version->short_name ?: $version->name)."-{$topic}.csv";

        if ($topic === 'event-version') {
            return CsvExport::stream(
                $filename,
                ['key', 'value'],
                array_map(fn (array $pair): array => [$pair['key'], $pair['value']], $downloads->eventVersionPairs($version)),
            );
        }

        $records = match ($topic) {
            'schools' => $downloads->schoolRows($version),
            'teachers' => $downloads->teacherRows($version),
            'students' => $downloads->studentRows($version),
            'candidates' => $downloads->candidateRows($version),
            default => abort(404),
        };

        return CsvExport::stream(
            $filename,
            $records === [] ? [] : array_keys($records[0]),
            array_map(fn (array $record): array => array_values($record), $records),
        );
    }
}
