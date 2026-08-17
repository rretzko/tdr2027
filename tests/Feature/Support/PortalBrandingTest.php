<?php

declare(strict_types=1);

use App\Support\PortalBranding;

test('appName returns the raw app name for TDR', function () {
    config(['app.name' => 'TDR<dev>']);

    expect(PortalBranding::appName())->toBe('TDR<dev>')
        ->and(PortalBranding::appName(sfdi: false))->toBe('TDR<dev>');
});

test('appName swaps TDR for SF, preserving any environment suffix', function () {
    config(['app.name' => 'TDR<dev>']);
    expect(PortalBranding::appName(sfdi: true))->toBe('SF<dev>');

    config(['app.name' => 'TDR']);
    expect(PortalBranding::appName(sfdi: true))->toBe('SF');

    config(['app.name' => 'TDR<staging>']);
    expect(PortalBranding::appName(sfdi: true))->toBe('SF<staging>');
});
