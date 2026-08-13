<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\Vendor;
use App\Models\Version;

/**
 * Resolves the configured PaymentGatewayContract implementation for a
 * Version's Event — see epayment-integration.md §2.1. Vendor::gatewayClass()/
 * app() mirrors the existing CutoffStrategy::strategyClass()/app()
 * resolution pattern in EnsembleCutoffService. The credential is looked up
 * via the Event, not the Version — see EventEpaymentConfig's own docblock.
 */
final class PaymentGatewayFactory
{
    public function make(Version $version): PaymentGatewayContract
    {
        $config = $version->eventEpaymentConfig();

        // getRawOriginal(), not the magic-cast property: Larastan can't infer
        // EventEpaymentConfig::$vendor's Vendor type through the casts()
        // accessor — same quirk as CutoffStrategy in EnsembleCutoffService.
        $rawVendor = $config?->getRawOriginal('vendor');

        abort_if($rawVendor === null, 422, 'E-payment is not configured for this Event.');

        return app(Vendor::from($rawVendor)->gatewayClass());
    }
}
