<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\EventStatus;
use App\Livewire\Events\VersionScorecard;
use App\Models\Candidate;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Models\Version;
use App\Services\VersionAnalyticsSnapshotService;
use App\Services\VersionRoleService;
use App\Support\Charts\SvgCharts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * @return array{version: Version, manager: User}
 */
function analyticsReportVersion(): array
{
    $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => EventStatus::Closed]);
    $manager = User::factory()->create();
    app(VersionRoleService::class)->withVersion($version, fn () => $manager->assignRole('Event Manager'));

    return ['version' => $version, 'manager' => $manager];
}

test('an Event Manager downloads the analytics report built from the Close snapshot', function () {
    ['version' => $version, 'manager' => $manager] = analyticsReportVersion();
    Candidate::factory()->create(['version_id' => $version->id, 'status' => CandidateStatus::Accepted]);
    app(VersionAnalyticsSnapshotService::class)->capture($version);

    $response = actingAs($manager)->get(route('events.versions.scorecard.analytics-report', $version));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('analytics-report');
});

test('the report is a 404 until the Version has a Close snapshot', function () {
    ['version' => $version, 'manager' => $manager] = analyticsReportVersion();

    actingAs($manager)->get(route('events.versions.scorecard.analytics-report', $version))->assertNotFound();
});

test('someone who can\'t manage the event is refused', function () {
    ['version' => $version] = analyticsReportVersion();
    app(VersionAnalyticsSnapshotService::class)->capture($version);

    actingAs(User::factory()->create())->get(route('events.versions.scorecard.analytics-report', $version))->assertForbidden();
});

test('the Scorecard offers the download only once a snapshot exists', function () {
    ['version' => $version, 'manager' => $manager] = analyticsReportVersion();

    Livewire::actingAs($manager)
        ->test(VersionScorecard::class, ['version' => $version])
        ->assertDontSee('Download analytics report');

    app(VersionAnalyticsSnapshotService::class)->capture($version);

    Livewire::actingAs($manager)
        ->test(VersionScorecard::class, ['version' => $version])
        ->assertSee('Download analytics report');
});

test('the SVG charts are well-formed images', function () {
    $uris = [
        SvgCharts::columns([['x' => 'Mon', 'a' => 3], ['x' => 'Tue', 'a' => 0]], ['a' => SvgCharts::BLUE], 'x'),
        SvgCharts::rangeBars([['label' => 'S1 & <Alto>', 'line' => 90, 'acceptedRange' => [70, 90], 'missedRange' => null]]),
        SvgCharts::pie([['label' => 'S1', 'count' => 3, 'color' => '#3b82f6'], ['label' => 'A1', 'count' => 1, 'color' => '#f97316']]),
        SvgCharts::pie([['label' => 'S1', 'count' => 2, 'color' => '#3b82f6']]),
    ];

    foreach ($uris as $uri) {
        expect($uri)->toStartWith('data:image/svg+xml;base64,');
        $xml = simplexml_load_string(base64_decode(substr($uri, strlen('data:image/svg+xml;base64,'))));
        expect($xml)->not->toBeFalse();
    }
});
