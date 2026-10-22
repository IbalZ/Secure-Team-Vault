<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\VaultCredential;
use JsonException;
use RuntimeException;
use UnexpectedValueException;

final class VaultCryptoService
{
    private const CIPHER = 'aes-256-gcm';

    private const KEY_BYTES = 32;

    private const NONCE_BYTES = 12;

    private const TAG_BYTES = 16;

    /** @var array<int, string|null> */
    private array $keys;

    private int $activeKeyVersion;

    /**
     * Supplying arguments directly is useful for isolated tests. Application
     * code normally lets the service read the versioned keys from config.
     *
     * @param  array<int|string, string|null>|null  $keys
     */
    public function __construct(?array $keys = null, ?int $activeKeyVersion = null)
    {
        $configuredKeys = $keys ?? (array) config('vault.keys', []);

        $this->keys = [];
        foreach ($configuredKeys as $version => $key) {
            $this->keys[(int) $version] = is_string($key) ? $key : null;
        }

        $this->activeKeyVersion = $activeKeyVersion
            ?? (int) config('vault.active_key_version', 1);
    }

    /**
     * Encrypt the recoverable fields of one credential.
     *
     * @param  array{username: string, password: string, notes?: string|null}  $secret
     * @return array{encrypted_payload: string, nonce: string, auth_tag: string, key_version: int, payload_version: int}
     */
    public function encrypt(array $secret, string $credentialUuid, int $payloadVersion = 1): array
    {
        if ($credentialUuid === '' || $payloadVersion < 1) {
            throw new UnexpectedValueException('A credential UUID and positive payload version are required.');
        }

        $normalized = $this->normalizeSecret($secret);
        $keyVersion = $this->activeKeyVersion;
        $key = $this->keyForVersion($keyVersion);
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';

        try {
            $plaintext = json_encode(
                $normalized,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('The credential secret could not be encoded.', 0, $exception);
        }

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->additionalAuthenticatedData($credentialUuid, $payloadVersion, $keyVersion),
            self::TAG_BYTES,
        );

        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('The credential secret could not be encrypted.');
        }

        return [
            'encrypted_payload' => base64_encode($ciphertext),
            'nonce' => base64_encode($nonce),
            'auth_tag' => base64_encode($tag),
            'key_version' => $keyVersion,
            'payload_version' => $payloadVersion,
        ];
    }

    /**
     * Explicitly decrypt a credential only after its policy has authorized the
     * caller. The model intentionally has no automatic decryption accessor.
     *
     * @param  VaultCredential|array<string, mixed>  $credential
     * @return array{username: string, password: string, notes: string|null}
     */
    public function decrypt(VaultCredential|array $credential): array
    {
        $uuid = $this->stringValue($credential, 'uuid');
        $keyVersion = $this->positiveIntegerValue($credential, 'key_version');
        $payloadVersion = $this->positiveIntegerValue($credential, 'payload_version');
        $key = $this->keyForVersion($keyVersion);

        $ciphertext = $this->decodeBytes(
            $this->stringValue($credential, 'encrypted_payload'),
            null,
            'ciphertext',
        );
        $nonce = $this->decodeBytes(
            $this->stringValue($credential, 'nonce'),
            self::NONCE_BYTES,
            'nonce',
        );
        $tag = $this->decodeBytes(
            $this->stringValue($credential, 'auth_tag'),
            self::TAG_BYTES,
            'authentication tag',
        );

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->additionalAuthenticatedData($uuid, $payloadVersion, $keyVersion),
        );

        if ($plaintext === false) {
            throw new RuntimeException('The credential secret failed authentication and cannot be decrypted.');
        }

        try {
            $secret = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The credential secret is not a valid payload.', 0, $exception);
        }

        if (! is_array($secret)) {
            throw new RuntimeException('The credential secret is not a valid payload.');
        }

        return $this->normalizeSecret($secret);
    }

    /**
     * Re-encrypt using the active key and a newly generated nonce.
     *
     * @param  VaultCredential|array<string, mixed>  $credential
     * @return array{encrypted_payload: string, nonce: string, auth_tag: string, key_version: int, payload_version: int}
     */
    public function rotate(VaultCredential|array $credential): array
    {
        $secret = $this->decrypt($credential);

        return $this->encrypt(
            $secret,
            $this->stringValue($credential, 'uuid'),
            $this->positiveIntegerValue($credential, 'payload_version'),
        );
    }

    private function additionalAuthenticatedData(
        string $credentialUuid,
        int $payloadVersion,
        int $keyVersion,
    ): string {
        return sprintf(
            'secureteam-vault|credential|%s|payload-v%d|key-v%d',
            $credentialUuid,
            $payloadVersion,
            $keyVersion,
        );
    }

    /**
     * @param  array<string, mixed>  $secret
     * @return array{username: string, password: string, notes: string|null}
     */
    private function normalizeSecret(array $secret): array
    {
        if (! array_key_exists('username', $secret) || ! is_string($secret['username'])) {
            throw new UnexpectedValueException('The credential username must be a string.');
        }

        if (! array_key_exists('password', $secret) || ! is_string($secret['password'])) {
            throw new UnexpectedValueException('The credential password must be a string.');
        }

        $notes = $secret['notes'] ?? null;
        if ($notes !== null && ! is_string($notes)) {
            throw new UnexpectedValueException('Credential notes must be a string or null.');
        }

        return [
            'username' => $secret['username'],
            'password' => $secret['password'],
            'notes' => $notes,
        ];
    }

    private function keyForVersion(int $version): string
    {
        $configured = $this->keys[$version] ?? null;

        if (! is_string($configured) || $configured === '') {
            throw new RuntimeException("Vault encryption key version {$version} is not configured.");
        }

        $key = str_starts_with($configured, 'base64:')
            ? base64_decode(substr($configured, 7), true)
            : $configured;

        if (! is_string($key) || strlen($key) !== self::KEY_BYTES) {
            throw new RuntimeException("Vault encryption key version {$version} must contain exactly 32 bytes.");
        }

        return $key;
    }

    private function decodeBytes(string $encoded, ?int $expectedLength, string $field): string
    {
        $decoded = base64_decode($encoded, true);

        if ($decoded === false || ($expectedLength !== null && strlen($decoded) !== $expectedLength)) {
            throw new RuntimeException("The stored credential {$field} is invalid.");
        }

        return $decoded;
    }

    /** @param VaultCredential|array<string, mixed> $credential */
    private function stringValue(VaultCredential|array $credential, string $field): string
    {
        $value = is_array($credential)
            ? ($credential[$field] ?? null)
            : $credential->getAttribute($field);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("The stored credential {$field} is invalid.");
        }

        return $value;
    }

    /** @param VaultCredential|array<string, mixed> $credential */
    private function positiveIntegerValue(VaultCredential|array $credential, string $field): int
    {
        $value = is_array($credential)
            ? ($credential[$field] ?? null)
            : $credential->getAttribute($field);

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new RuntimeException("The stored credential {$field} is invalid.");
        }

        $value = (int) $value;
        if ($value < 1) {
            throw new RuntimeException("The stored credential {$field} is invalid.");
        }

        return $value;
    }
}
