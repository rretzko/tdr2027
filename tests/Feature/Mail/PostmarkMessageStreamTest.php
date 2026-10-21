<?php

declare(strict_types=1);

use App\Enums\VersionInvitationStatus;
use App\Mail\DirectorWeeklyUpdateMail;
use App\Mail\MondayMorningScorecardMail;
use App\Mail\PacketReceivedMail;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Services\DirectorWeeklyUpdateService;
use App\Services\VersionScorecardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    // CandidateObserver writes candidate_status_history rows keyed by Auth::id().
    actingAs(User::factory()->create());
});

/**
 * Swaps the postmark mailer's transport for the real Postmark API transport on a
 * fake HTTP client, and returns the JSON payloads Postmark would have received.
 *
 * @return ArrayObject<int, array<string, mixed>>
 */
function postmarkStreamTestPayloads(): ArrayObject
{
    /** @var ArrayObject<int, array<string, mixed>> $payloads */
    $payloads = new ArrayObject;
    $client = new MockHttpClient(function (string $method, string $url, array $options) use ($payloads): JsonMockResponse {
        $payloads[] = json_decode($options['body'], true);

        return new JsonMockResponse(['MessageID' => 'test-message-id', 'ErrorCode' => 0]);
    });

    // Resolving the mailer builds its default transport first, which needs a key.
    config(['services.postmark.key' => 'test-token']);
    Mail::mailer('postmark')->setSymfonyTransport(new PostmarkApiTransport('test-token', $client));

    return $payloads;
}

test('the director weekly update is sent on the broadcast stream', function () {
    $payloads = postmarkStreamTestPayloads();
    $invitation = VersionInvitation::create([
        'version_id' => Version::factory()->active()->create()->id,
        'teacher_id' => Teacher::factory()->create()->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    Mail::mailer('postmark')->to('director@example.com')->send(
        new DirectorWeeklyUpdateMail(app(DirectorWeeklyUpdateService::class)->updateFor($invitation->fresh())),
    );

    expect($payloads)->toHaveCount(1)
        ->and($payloads[0]['MessageStream'])->toBe('broadcast');
});

test('the monday morning scorecard is sent on the broadcast stream', function () {
    $payloads = postmarkStreamTestPayloads();
    $version = Version::factory()->active()->create();

    Mail::mailer('postmark')->to('em@example.com')->send(
        new MondayMorningScorecardMail($version, app(VersionScorecardService::class)->metricsFor($version)),
    );

    expect($payloads[0]['MessageStream'])->toBe('broadcast');
});

test('each digest stream can be overridden by config', function () {
    $payloads = postmarkStreamTestPayloads();
    $version = Version::factory()->active()->create();
    config(['services.postmark.streams.monday_scorecard' => 'em-scorecard']);

    Mail::mailer('postmark')->to('em@example.com')->send(
        new MondayMorningScorecardMail($version, app(VersionScorecardService::class)->metricsFor($version)),
    );

    expect($payloads[0]['MessageStream'])->toBe('em-scorecard');
});

test('transactional mail stays on the default stream', function () {
    $payloads = postmarkStreamTestPayloads();

    Mail::mailer('postmark')->to('director@example.com')->send(new PacketReceivedMail(Version::factory()->active()->create()));

    expect($payloads[0])->not->toHaveKey('MessageStream');
});
