<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Version;
use App\Models\VersionAnalyticsSnapshot;
use App\Services\VersionRoleAssignmentService;
use App\Support\Charts\EnsemblePieCharts;
use App\Support\Charts\SvgCharts;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The end-of-Version analytics report for Event Managers
 * (docs/plans/version-analytics-report.md), downloaded from the
 * Scorecard. Built only from the Version's Close snapshot
 * (version_analytics_snapshots), never live data, so the report says the
 * same thing every time it's downloaded. Charts are server-drawn SVG
 * (SvgCharts), since DomPDF can't run the Scorecard's chart JavaScript.
 */
class VersionAnalyticsReportPdfController extends Controller
{
    public function __invoke(Version $version, VersionRoleAssignmentService $roles): Response
    {
        abort_unless($roles->canManageEvent(Auth::user(), $version->event), 403);

        $snapshot = VersionAnalyticsSnapshot::where('version_id', $version->id)->first();
        abort_if($snapshot === null, 404);

        $registration = $snapshot->registration;
        $adjudication = $snapshot->adjudication;
        $selection = $snapshot->selection;
        $analysis = $selection['analysis'] ?? null;

        return Pdf::loadView('pdf.analytics-report', [
            'version' => $version,
            'snapshot' => $snapshot,
            'registration' => $registration,
            'adjudication' => $adjudication,
            'selection' => $selection,
            'analysis' => $analysis,
            'charts' => [
                'registrationTiming' => isset($registration['timing']['rows'])
                    ? SvgCharts::columns($registration['timing']['rows'], ['early' => SvgCharts::BLUE, 'middle' => SvgCharts::GREEN, 'late' => SvgCharts::ORANGE], 'x')
                    : null,
                'dailySaves' => ($adjudication['daily'] ?? []) !== []
                    ? SvgCharts::columns($adjudication['daily'], ['saves' => SvgCharts::BLUE], 'x', 700, 160)
                    : null,
                'ranges' => $analysis !== null && $analysis['lines'] !== [] ? SvgCharts::rangeBars(array_map(fn (array $line): array => [
                    'label' => $line['voicePart']['abbr'].($line['ensemble'] === null ? '' : ' · '.$line['ensemble']['name']),
                    'line' => $line['line'],
                    'acceptedRange' => $line['acceptedRange'] ?? null,
                    'missedRange' => $line['missedRange'] ?? null,
                ], $analysis['lines'])) : null,
                'pies' => $this->pies($selection),
            ],
        ])->setPaper('letter', 'portrait')->download(Str::slug("analytics-report-{$version->name}").'.pdf');
    }

    /**
     * One pie per Ensemble of its accepted candidates by Voice Part, in the
     * snapshot's Voice Part order and the Scorecard's palette.
     *
     * @param  array<string, mixed>  $selection
     * @return list<array{name: string, total: int, image: string, slices: list<array{label: string, count: int, color: string}>}>
     */
    private function pies(array $selection): array
    {
        $voiceParts = collect($selection['voiceParts'] ?? [])->values();
        $colors = $voiceParts->mapWithKeys(fn (array $voicePart, int $index): array => [
            $voicePart['id'] => EnsemblePieCharts::PALETTE[$index % count(EnsemblePieCharts::PALETTE)],
        ]);
        $accepted = collect($selection['accepted'] ?? []);

        $pies = [];
        foreach ($selection['ensembles'] ?? [] as $ensemble) {
            $rows = $accepted->where('ensembleId', $ensemble['id']);
            $slices = $voiceParts
                ->map(fn (array $voicePart): array => [
                    'label' => (string) $voicePart['abbr'],
                    'count' => (int) $rows->where('voicePartId', $voicePart['id'])->sum('count'),
                    'color' => (string) $colors[$voicePart['id']],
                ])
                ->filter(fn (array $slice): bool => $slice['count'] > 0)
                ->values()
                ->all();

            $total = array_sum(array_column($slices, 'count'));
            if ($total > 0) {
                $pies[] = ['name' => (string) $ensemble['name'], 'total' => $total, 'image' => SvgCharts::pie($slices), 'slices' => $slices];
            }
        }

        return $pies;
    }
}
