<?php

namespace Tests;

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
}
