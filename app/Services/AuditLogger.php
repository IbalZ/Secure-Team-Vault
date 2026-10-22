<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditChainHead;
use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class AuditLogger
{
    private const KEY_BYTES = 32;

    private const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private const SENSITIVE_KEY_PARTS = [
        'password',
        'secret',
        'token',
        'ciphertext',
        'encrypted_payload',
        'nonce',
        'auth_tag',
        'plaintext',
    ];

    private string $hmacKey;

    private string $chainId;

    public function __construct(?string $configuredKey = null, ?string $chainId = null)
    {
        $this->hmacKey = $this->decodeKey(
            $configuredKey ?? (string) config('vault.audit.hmac_key', ''),
        );
        $this->chainId = $chainId ?? (string) config('vault.audit.chain_id', 'main');

        if ($this->chainId === '' || strlen($this->chainId) > 64) {
            throw new RuntimeException('The configured audit chain identifier is invalid.');
        }
    }

    /**
     * Append one event atomically. If this method throws, a caller about to
     * disclose a secret must fail closed and return no plaintext.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        ?User $actor,
        string $action,
        ?string $targetType = null,
        string|int|null $targetId = null,
        array $metadata = [],
        ?Request $request = null,
    ): AuditLog {
        $action = trim($action);
        if ($action === '' || strlen($action) > 100 || preg_match('/\A[a-z0-9_.-]+\z/', $action) !== 1) {
            throw new InvalidArgumentException('Audit actions must use lowercase dot-separated identifiers.');
        }

        if ($targetType !== null && strlen($targetType) > 100) {
            throw new InvalidArgumentException('The audit target type is too long.');
        }

        $normalizedTargetId = $targetId === null ? null : (string) $targetId;
        if ($normalizedTargetId !== null && strlen($normalizedTargetId) > 64) {
            throw new InvalidArgumentException('The audit target identifier is too long.');
        }

        $metadata = $this->normalizeMetadata($metadata);
        $metadataJson = $this->canonicalJson($metadata);
        if (strlen($metadataJson) > 16384) {
            throw new InvalidArgumentException('Audit metadata exceeds the 16 KiB limit.');
        }

        $request ??= app()->bound('request') ? app('request') : null;

        return DB::transaction(function () use (
            $actor,
            $action,
            $targetType,
            $normalizedTargetId,
            $metadata,
            $metadataJson,
            $request,
        ): AuditLog {
            $head = AuditChainHead::query()
                ->whereKey($this->chainId)
                ->lockForUpdate()
                ->first();

            if ($head === null) {
                throw new RuntimeException(
                    "Audit chain {$this->chainId} is not initialized; run the audit migration first.",
                );
            }

            $eventUuid = (string) Str::uuid();
            $occurredAt = now('UTC');
            $occurredAtText = $occurredAt->format('Y-m-d\TH:i:s.u\Z');
            $requestId = $this->requestId($request);
            $ipAddress = $request?->ip();
            $ipAddress = is_string($ipAddress) ? substr($ipAddress, 0, 45) : null;
            $userAgent = $request?->userAgent();
            $userAgentHash = is_string($userAgent) && $userAgent !== ''
                ? hash('sha256', $userAgent)
                : null;

            $role = $actor?->getAttribute('role');
            $role = $role instanceof BackedEnum ? $role->value : ($role === null ? null : (string) $role);
            $actorId = $actor?->getKey();
            $actorId = $actorId === null ? null : (int) $actorId;

            $event = [
                'schema' => 1,
                'event_uuid' => $eventUuid,
                'chain_id' => $this->chainId,
                'occurred_at' => $occurredAtText,
                'actor_user_id' => $actorId,
                'actor_role' => $role,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $normalizedTargetId,
                'request_id' => $requestId,
                'ip_address' => $ipAddress,
                'user_agent_hash' => $userAgentHash,
                'metadata' => $metadata,
            ];
            $eventPayload = $this->canonicalJson($event);
            $previousHash = $head->last_hash;
            $entryHash = hash_hmac(
                'sha256',
                ($previousHash ?? self::GENESIS_HASH)."\n".$eventPayload,
                $this->hmacKey,
            );

            $log = AuditLog::query()->create([
                'event_uuid' => $eventUuid,
                'chain_id' => $this->chainId,
                'occurred_at' => $occurredAt,
                'actor_user_id' => $actorId,
                'actor_role' => $role,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $normalizedTargetId,
                'request_id' => $requestId,
                'ip_address' => $ipAddress,
                'user_agent_hash' => $userAgentHash,
                'metadata' => $metadataJson,
                'event_payload' => $eventPayload,
                'previous_hash' => $previousHash,
                'entry_hash' => $entryHash,
            ]);

            $head->forceFill([
                'last_event_uuid' => $eventUuid,
                'last_hash' => $entryHash,
                'event_count' => $head->event_count + 1,
                'updated_at' => $occurredAt,
            ])->save();

            return $log;
        }, 3);
    }

    /** @param array<string, mixed> $metadata */
    private function normalizeMetadata(array $metadata): array
    {
        $normalized = [];

        foreach ($metadata as $key => $value) {
            $key = (string) $key;
            $searchableKey = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $key) ?? $key);

            foreach (self::SENSITIVE_KEY_PARTS as $sensitivePart) {
                if (str_contains($searchableKey, $sensitivePart)) {
                    throw new InvalidArgumentException(
                        "Sensitive audit metadata key [{$key}] is not allowed.",
                    );
                }
            }

            $normalized[$key] = $this->normalizeMetadataValue($value);
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private function normalizeMetadataValue(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn (mixed $item): mixed => $this->normalizeMetadataValue($item), $value);
            }

            return $this->normalizeMetadata($value);
        }

        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        throw new InvalidArgumentException('Audit metadata must contain only scalar, date, enum, or array values.');
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Audit event data could not be encoded.', 0, $exception);
        }
    }

    private function requestId(?Request $request): string
    {
        $provided = $request?->headers->get('X-Request-ID');

        return is_string($provided) && Str::isUuid($provided)
            ? $provided
            : (string) Str::uuid();
    }

    private function decodeKey(string $configured): string
    {
        $key = str_starts_with($configured, 'base64:')
            ? base64_decode(substr($configured, 7), true)
            : $configured;

        if (! is_string($key) || strlen($key) !== self::KEY_BYTES) {
            throw new RuntimeException('AUDIT_HMAC_KEY must contain exactly 32 bytes.');
        }

        return $key;
    }
}
