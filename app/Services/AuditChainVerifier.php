<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditChainHead;
use App\Models\AuditLog;
use DateTimeInterface;
use JsonException;
use RuntimeException;

final class AuditChainVerifier
{
    private const KEY_BYTES = 32;

    private const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private string $hmacKey;

    public function __construct(?string $configuredKey = null)
    {
        $this->hmacKey = $this->decodeKey(
            $configuredKey ?? (string) config('vault.audit.hmac_key', ''),
        );
    }

    /**
     * Verify all stored events and the separately locked chain head.
     *
     * @return array{valid: bool, chain_id: string, checked_events: int, errors: list<array{code: string, event_id: int|null, message: string}>, head_hash: string|null}
     */
    public function verify(string $chainId = 'main'): array
    {
        $head = AuditChainHead::query()->find($chainId);
        $logs = AuditLog::query()
            ->where('chain_id', $chainId)
            ->orderBy('id')
            ->cursor();

        return $this->verifyRecords($logs, $head, $chainId);
    }

    /**
     * Public for deterministic unit testing and offline verification tools.
     *
     * @param  iterable<AuditLog>  $logs
     * @return array{valid: bool, chain_id: string, checked_events: int, errors: list<array{code: string, event_id: int|null, message: string}>, head_hash: string|null}
     */
    public function verifyRecords(
        iterable $logs,
        ?AuditChainHead $head,
        string $chainId = 'main',
    ): array {
        $errors = [];
        $count = 0;
        $expectedPreviousHash = null;
        $lastEventUuid = null;

        foreach ($logs as $log) {
            $count++;
            $eventId = is_numeric($log->getKey()) ? (int) $log->getKey() : null;

            if ($log->chain_id !== $chainId) {
                $errors[] = $this->error(
                    'chain_id_mismatch',
                    $eventId,
                    'The event belongs to a different audit chain.',
                );
            }

            if (! $this->nullableHashEquals($expectedPreviousHash, $log->previous_hash)) {
                $errors[] = $this->error(
                    'previous_hash_mismatch',
                    $eventId,
                    'The event does not point to the preceding event hash.',
                );
            }

            $calculatedHash = hash_hmac(
                'sha256',
                ($log->previous_hash ?? self::GENESIS_HASH)."\n".$log->event_payload,
                $this->hmacKey,
            );

            if (! is_string($log->entry_hash) || ! hash_equals($calculatedHash, $log->entry_hash)) {
                $errors[] = $this->error(
                    'entry_hash_mismatch',
                    $eventId,
                    'The event HMAC does not match its stored data.',
                );
            }

            $payload = $this->decodePayload($log, $eventId, $errors);
            if ($payload !== null && $payload !== $this->expectedPayload($log)) {
                $errors[] = $this->error(
                    'event_payload_mismatch',
                    $eventId,
                    'The protected event payload does not match the searchable columns.',
                );
            }

            $expectedPreviousHash = is_string($log->entry_hash) ? $log->entry_hash : null;
            $lastEventUuid = is_string($log->event_uuid) ? $log->event_uuid : null;
        }

        if ($head === null) {
            $errors[] = $this->error(
                'missing_chain_head',
                null,
                'The audit chain head is missing.',
            );
        } else {
            if ($head->chain_id !== $chainId) {
                $errors[] = $this->error(
                    'head_chain_id_mismatch',
                    null,
                    'The chain head identifier is incorrect.',
                );
            }

            if ((int) $head->event_count !== $count) {
                $errors[] = $this->error(
                    'event_count_mismatch',
                    null,
                    'The chain head event count does not match the stored events.',
                );
            }

            if (! $this->nullableHashEquals($expectedPreviousHash, $head->last_hash)) {
                $errors[] = $this->error(
                    'head_hash_mismatch',
                    null,
                    'The chain head does not match the final event hash.',
                );
            }

            if ($lastEventUuid !== $head->last_event_uuid) {
                $errors[] = $this->error(
                    'head_event_mismatch',
                    null,
                    'The chain head does not reference the final event UUID.',
                );
            }
        }

        return [
            'valid' => $errors === [],
            'chain_id' => $chainId,
            'checked_events' => $count,
            'errors' => $errors,
            'head_hash' => $head?->last_hash,
        ];
    }

    /**
     * @param  list<array{code: string, event_id: int|null, message: string}>  $errors
     * @return array<string, mixed>|null
     */
    private function decodePayload(AuditLog $log, ?int $eventId, array &$errors): ?array
    {
        try {
            $payload = json_decode($log->event_payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $errors[] = $this->error(
                'invalid_event_payload',
                $eventId,
                'The protected event payload is not valid JSON.',
            );

            return null;
        }

        if (! is_array($payload)) {
            $errors[] = $this->error(
                'invalid_event_payload',
                $eventId,
                'The protected event payload must be a JSON object.',
            );

            return null;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function expectedPayload(AuditLog $log): array
    {
        try {
            $metadata = json_decode($log->metadata, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $metadata = '__invalid_metadata__';
        }

        $occurredAt = $log->occurred_at;
        $occurredAt = $occurredAt instanceof DateTimeInterface
            ? $occurredAt->format('Y-m-d\TH:i:s.u\Z')
            : (string) $occurredAt;

        return [
            'schema' => 1,
            'event_uuid' => $log->event_uuid,
            'chain_id' => $log->chain_id,
            'occurred_at' => $occurredAt,
            'actor_user_id' => $log->actor_user_id,
            'actor_role' => $log->actor_role,
            'action' => $log->action,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'request_id' => $log->request_id,
            'ip_address' => $log->ip_address,
            'user_agent_hash' => $log->user_agent_hash,
            'metadata' => $metadata,
        ];
    }

    private function nullableHashEquals(?string $expected, mixed $actual): bool
    {
        if ($expected === null || $actual === null) {
            return $expected === null && $actual === null;
        }

        return is_string($actual) && hash_equals($expected, $actual);
    }

    /** @return array{code: string, event_id: int|null, message: string} */
    private function error(string $code, ?int $eventId, string $message): array
    {
        return [
            'code' => $code,
            'event_id' => $eventId,
            'message' => $message,
        ];
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
