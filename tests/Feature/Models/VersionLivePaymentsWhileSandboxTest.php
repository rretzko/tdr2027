<?php

declare(strict_types=1);

use App\Enums\PaymentEnvironment;
use App\Models\EventEpaymentConfig;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.payments.environment' => PaymentEnvironment::Production->value]);
});

it('is true for a sandbox version when payments are live and the event has a production vendor', function (): void {
    $version = Version::factory()->create();
    EventEpaymentConfig::factory()->create(['event_id' => $version->event_id, 'environment' => PaymentEnvironment::Production]);

    expect($version->livePaymentsWhileSandbox())->toBeTrue();

    expect(Blade::render('<x-live-payments-sandbox-warning :version="$version" />', ['version' => $version]))
        ->toContain('electronic payments are live');
});

it('is false for an active version', function (): void {
    $version = Version::factory()->active()->create();
    EventEpaymentConfig::factory()->create(['event_id' => $version->event_id, 'environment' => PaymentEnvironment::Production]);

    expect($version->livePaymentsWhileSandbox())->toBeFalse();
});

it('is false when the deployment is in sandbox payment mode', function (): void {
    config(['services.payments.environment' => PaymentEnvironment::Sandbox->value]);
    $version = Version::factory()->create();
    EventEpaymentConfig::factory()->create(['event_id' => $version->event_id, 'environment' => PaymentEnvironment::Sandbox]);

    expect($version->livePaymentsWhileSandbox())->toBeFalse();

    expect(Blade::render('<x-live-payments-sandbox-warning :version="$version" />', ['version' => $version]))
        ->not->toContain('electronic payments are live');
});

it('is false when the event has no production vendor configured', function (): void {
    $version = Version::factory()->create();
    EventEpaymentConfig::factory()->disabled()->create(['event_id' => $version->event_id, 'environment' => PaymentEnvironment::Production]);

    expect($version->livePaymentsWhileSandbox())->toBeFalse();
});
