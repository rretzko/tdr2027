<?php

declare(strict_types=1);

use App\Enums\ObligationDecision;
use App\Enums\VersionInvitationStatus;
use App\Enums\VersionObligationStatus;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VersionObligation;
use App\Models\VersionObligationResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('VersionObligationObserver sanitizes body on direct model creation, not just through a form', function () {
    $version = Version::factory()->create();

    $obligation = VersionObligation::create([
        'version_id' => $version->id,
        'body' => '<p>Safe</p><script>alert(1)</script>',
    ]);

    expect($obligation->body)->toContain('<p>Safe</p>');
    expect($obligation->body)->not->toContain('<script');
});

test('VersionObligationObserver only re-sanitizes when body is dirty', function () {
    $version = Version::factory()->create();

    $obligation = VersionObligation::create([
        'version_id' => $version->id,
        'body' => '<p>Original</p>',
    ]);

    $cleanedBody = $obligation->body;

    $obligation->update(['title' => 'A title, no body change']);

    expect($obligation->fresh()->body)->toBe($cleanedBody);
});

test('VersionObligationResponseObserver sets VersionInvitation status to Obligated on an accepted decision', function () {
    $version = Version::factory()->create();
    $invitation = VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => Teacher::factory()->create()->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);
    $obligation = VersionObligation::create(['version_id' => $version->id, 'body' => '<p>Text</p>']);

    VersionObligationResponse::create([
        'version_invitation_id' => $invitation->id,
        'version_obligation_id' => $obligation->id,
        'decision' => ObligationDecision::Accepted->value,
        'decided_at' => now(),
        'obligation_snapshot' => $obligation->body,
    ]);

    expect($invitation->fresh()->getRawOriginal('status'))->toBe('obligated');
});

test('VersionObligationResponseObserver leaves status alone on a rejected decision', function () {
    $version = Version::factory()->create();
    $invitation = VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => Teacher::factory()->create()->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);
    $obligation = VersionObligation::create(['version_id' => $version->id, 'body' => '<p>Text</p>']);

    VersionObligationResponse::create([
        'version_invitation_id' => $invitation->id,
        'version_obligation_id' => $obligation->id,
        'decision' => ObligationDecision::Rejected->value,
        'decided_at' => now(),
        'obligation_snapshot' => $obligation->body,
    ]);

    expect($invitation->fresh()->getRawOriginal('status'))->toBe('invited');
});

/**
 * Regression test: getRawOriginal() inside a `saved` hook lags by one save
 * (syncOriginal() runs after the event fires), which previously caused this
 * exact toggle sequence to flip Obligated status one step out of sync. The
 * fix reads getAttributes() instead — see VersionObligationResponseObserver.
 */
test('toggling accepted to rejected to accepted correctly ends Obligated, not one step behind', function () {
    $version = Version::factory()->create();
    $invitation = VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => Teacher::factory()->create()->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);
    $obligation = VersionObligation::create(['version_id' => $version->id, 'body' => '<p>Text</p>']);

    $response = VersionObligationResponse::create([
        'version_invitation_id' => $invitation->id,
        'version_obligation_id' => $obligation->id,
        'decision' => ObligationDecision::Accepted->value,
        'decided_at' => now(),
        'obligation_snapshot' => $obligation->body,
    ]);

    expect($invitation->fresh()->getRawOriginal('status'))->toBe('obligated');

    $response->update(['decision' => ObligationDecision::Rejected->value, 'decided_at' => now()]);
    expect($invitation->fresh()->getRawOriginal('status'))->toBe('obligated');

    $response->update(['decision' => ObligationDecision::Accepted->value, 'decided_at' => now()]);
    expect($invitation->fresh()->getRawOriginal('status'))->toBe('obligated');
});

test('a new VersionObligation defaults to draft status even though updateOrCreate leaves it unset in memory', function () {
    $version = Version::factory()->create();

    $obligation = VersionObligation::updateOrCreate(
        ['version_id' => $version->id],
        ['body' => '<p>Text</p>'],
    );

    expect($obligation->fresh()->getRawOriginal('status'))->toBe(VersionObligationStatus::Draft->value);
});
