<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sandbox vs production vendor credentials — see epayment-integration.md
 * §1.2. Which one is "active" for the whole app is driven by the app-wide
 * `services.payments.environment` config, not per-Event — an Event's
 * EventEpaymentConfig can hold both simultaneously (one row per
 * environment) so switching over doesn't require re-entering credentials.
 */
enum PaymentEnvironment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';
}
