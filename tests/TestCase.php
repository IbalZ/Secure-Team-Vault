<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic test-only keys keep feature tests independent from the
        // developer's .env and ensure the real vault keys never enter output.
        config()->set('vault.active_key_version', 1);
        config()->set('vault.keys', [
            1 => 'base64:'.base64_encode(str_repeat("\x51", 32)),
        ]);
        config()->set(
            'vault.audit.hmac_key',
            'base64:'.base64_encode(str_repeat("\xA4", 32)),
        );
    }
}
