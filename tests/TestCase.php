<?php

namespace Tests;

use App\Support\TurnstileVerifier;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Indicates whether the default seeder should run before each test.
     *
     * Lookup tables (pronouns, voice_parts, etc.) are seeded so that
     * foreign key defaults such as users.pronoun_id = 1 resolve correctly.
     */
    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Registration tests submit without a real widget token. Turnstile's
        // own tests (TurnstileVerificationTest) forget this instance to
        // exercise the real verifier against a faked siteverify endpoint.
        $this->app->instance(TurnstileVerifier::class, new class extends TurnstileVerifier
        {
            public function verify(string $token, string $action, ?string $ip): bool
            {
                return true;
            }
        });
    }
}
