<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\VaultCryptoService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class VaultCryptoServiceTest extends TestCase
{
    private const UUID = '018d5f42-3a1d-7f90-9d52-f11326c71234';

    private string $configuredKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configuredKey = 'base64:'.base64_encode(str_repeat("\xA7", 32));
    }

    public function test_it_round_trips_a_credential_with_a_12_byte_nonce_and_16_byte_tag(): void
    {
        $crypto = new VaultCryptoService([1 => $this->configuredKey], 1);
        $secret = [
            'username' => 'iqbal@example.test',
            'password' => 'Correct Horse Battery Staple!',
            'notes' => 'Recovery codes are stored offline.',
        ];

        $encrypted = $crypto->encrypt($secret, self::UUID);
        $decrypted = $crypto->decrypt(['uuid' => self::UUID, ...$encrypted]);

        self::assertSame($secret, $decrypted);
        self::assertSame(12, strlen((string) base64_decode($encrypted['nonce'], true)));
        self::assertSame(16, strlen((string) base64_decode($encrypted['auth_tag'], true)));
        self::assertStringNotContainsString($secret['password'], $encrypted['encrypted_payload']);
    }

    public function test_reencrypting_the_same_secret_uses_a_fresh_nonce_and_ciphertext(): void
    {
        $crypto = new VaultCryptoService([1 => $this->configuredKey], 1);
        $secret = ['username' => 'same-user', 'password' => 'same-password', 'notes' => null];

        $first = $crypto->encrypt($secret, self::UUID);
        $second = $crypto->encrypt($secret, self::UUID);

        self::assertNotSame($first['nonce'], $second['nonce']);
        self::assertNotSame($first['encrypted_payload'], $second['encrypted_payload']);
        self::assertNotSame($first['auth_tag'], $second['auth_tag']);
    }

    public function test_modified_ciphertext_is_rejected(): void
    {
        $crypto = new VaultCryptoService([1 => $this->configuredKey], 1);
        $encrypted = $crypto->encrypt(
            ['username' => 'user', 'password' => 'high-value-secret', 'notes' => null],
            self::UUID,
        );
        $ciphertext = (string) base64_decode($encrypted['encrypted_payload'], true);
        $ciphertext[0] = chr(ord($ciphertext[0]) ^ 1);
        $encrypted['encrypted_payload'] = base64_encode($ciphertext);

        $this->expectException(RuntimeException::class);
        $crypto->decrypt(['uuid' => self::UUID, ...$encrypted]);
    }

    public function test_ciphertext_cannot_be_moved_to_a_different_credential_uuid(): void
    {
        $crypto = new VaultCryptoService([1 => $this->configuredKey], 1);
        $encrypted = $crypto->encrypt(
            ['username' => 'user', 'password' => 'high-value-secret', 'notes' => null],
            self::UUID,
        );

        $this->expectException(RuntimeException::class);
        $crypto->decrypt([
            'uuid' => '018d5f42-3a1d-7f90-9d52-f11326c79999',
            ...$encrypted,
        ]);
    }

    public function test_wrong_key_and_unknown_key_versions_are_rejected(): void
    {
        $encryptor = new VaultCryptoService([1 => $this->configuredKey], 1);
        $encrypted = $encryptor->encrypt(
            ['username' => 'user', 'password' => 'high-value-secret', 'notes' => null],
            self::UUID,
        );
        $decryptor = new VaultCryptoService(
            [1 => 'base64:'.base64_encode(str_repeat("\xB8", 32))],
            1,
        );

        try {
            $decryptor->decrypt(['uuid' => self::UUID, ...$encrypted]);
            self::fail('A different key must not authenticate the ciphertext.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        $encrypted['key_version'] = 9;

        $this->expectException(RuntimeException::class);
        $encryptor->decrypt(['uuid' => self::UUID, ...$encrypted]);
    }
}
