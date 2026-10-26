<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side half of the Cloudflare Turnstile check on registration. Fails
 * closed: a missing/oversized token, missing config, network error, or any
 * mismatch in success/action/hostname rejects the submission.
 */
class TurnstileVerifier
{
    private const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function verify(string $token, string $action, ?string $ip): bool
    {
        $secret = (string) config('services.turnstile.secret_key');
        /** @var list<string> $hostnames */
        $hostnames = config('services.turnstile.hostnames', []);

        if ($token === '' || strlen($token) > 2048 || $secret === '' || $hostnames === []) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::ENDPOINT, array_filter([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]));
        } catch (ConnectionException $e) {
            Log::warning('Turnstile siteverify unreachable', ['message' => $e->getMessage()]);

            return false;
        }

        if (! $response->ok()) {
            return false;
        }

        $result = $response->json();

        if (! is_array($result) || ($result['success'] ?? false) !== true) {
            return false;
        }

        // Cloudflare's published testing secret (local/dev only) answers with
        // hostname "example.com" and no action, so the action check can't
        // apply to it. Only our own configured secret can produce this flag.
        $isTestingKey = ($result['metadata']['result_with_testing_key'] ?? false) === true;

        if (! $isTestingKey && ($result['action'] ?? null) !== $action) {
            return false;
        }

        return in_array($result['hostname'] ?? null, $hostnames, true);
    }
}
