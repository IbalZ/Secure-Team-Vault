<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AuditChainHead;
use App\Models\AuditLog;
use App\Services\AuditChainVerifier;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class AuditChainVerifierTest extends TestCase
{
    private const HMAC_KEY = '0123456789abcdef0123456789abcdef';

    private const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function test_it_accepts_an_intact_chain(): void
    {
        [$logs, $head] = $this->twoEventChain();

        $result = (new AuditChainVerifier(self::HMAC_KEY))
            ->verifyRecords($logs, $head);

        self::assertTrue($result['valid']);
        self::assertSame(2, $result['checked_events']);
        self::assertSame([], $result['errors']);
    }

    public function test_it_detects_a_modified_event_payload(): void
    {
        [$logs, $head] = $this->twoEventChain();
        $logs[1]->setRawAttributes([
            ...$logs[1]->getAttributes(),
            'event_payload' => str_replace(
                'credential.updated',
                'credential.deleted',
                $logs[1]->event_payload,
            ),
        ]);

        $result = (new AuditChainVerifier(self::HMAC_KEY))
            ->verifyRecords($logs, $head);
        $codes = array_column($result['errors'], 'code');

        self::assertFalse($result['valid']);
        self::assertContains('entry_hash_mismatch', $codes);
        self::assertContains('event_payload_mismatch', $codes);
    }

    public function test_it_detects_a_deleted_middle_event_and_a_forged_head(): void
    {
        [$logs, $head] = $this->twoEventChain();
        $head->setRawAttributes([
            ...$head->getAttributes(),
            'last_hash' => str_repeat('f', 64),
        ]);

        $result = (new AuditChainVerifier(self::HMAC_KEY))
            ->verifyRecords([$logs[1]], $head);
        $codes = array_column($result['errors'], 'code');

        self::assertFalse($result['valid']);
        self::assertContains('previous_hash_mismatch', $codes);
        self::assertContains('event_count_mismatch', $codes);
        self::assertContains('head_hash_mismatch', $codes);
    }

    /** @return array{0: list<AuditLog>, 1: AuditChainHead} */
    private function twoEventChain(): array
    {
        $first = $this->makeLog(
            id: 1,
            eventUuid: '018d5f42-3a1d-7f90-9d52-f11326c70001',
            action: 'credential.created',
            occurredAt: '2026-09-12T01:00:00.000000Z',
            previousHash: null,
        );
        $second = $this->makeLog(
            id: 2,
            eventUuid: '018d5f42-3a1d-7f90-9d52-f11326c70002',
            action: 'credential.updated',
            occurredAt: '2026-09-12T01:01:00.000000Z',
            previousHash: $first->entry_hash,
        );

        $head = new AuditChainHead;
        $head->setRawAttributes([
            'chain_id' => 'main',
            'last_event_uuid' => $second->event_uuid,
            'last_hash' => $second->entry_hash,
            'event_count' => 2,
            'updated_at' => CarbonImmutable::parse('2026-09-12T01:01:00Z'),
        ]);

        return [[$first, $second], $head];
    }

    private function makeLog(
        int $id,
        string $eventUuid,
        string $action,
        string $occurredAt,
        ?string $previousHash,
    ): AuditLog {
        $metadata = ['changed_fields' => ['title']];
        $event = [
            'schema' => 1,
            'event_uuid' => $eventUuid,
            'chain_id' => 'main',
            'occurred_at' => $occurredAt,
            'actor_user_id' => 1,
            'actor_role' => 'admin',
            'action' => $action,
            'target_type' => 'vault_credential',
            'target_id' => '018d5f42-3a1d-7f90-9d52-f11326c71234',
            'request_id' => '018d5f42-3a1d-7f90-9d52-f11326c79998',
            'ip_address' => '127.0.0.1',
            'user_agent_hash' => hash('sha256', 'test-agent'),
            'metadata' => $metadata,
        ];
        $eventPayload = json_encode(
            $event,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        );
        $entryHash = hash_hmac(
            'sha256',
            ($previousHash ?? self::GENESIS_HASH)."\n".$eventPayload,
            self::HMAC_KEY,
        );

        $log = new AuditLog;
        $log->setRawAttributes([
            'id' => $id,
            'event_uuid' => $eventUuid,
            'chain_id' => 'main',
            'occurred_at' => CarbonImmutable::createFromFormat(
                'Y-m-d\TH:i:s.u\Z',
                $occurredAt,
                'UTC',
            ),
            'actor_user_id' => 1,
            'actor_role' => 'admin',
            'action' => $action,
            'target_type' => 'vault_credential',
            'target_id' => '018d5f42-3a1d-7f90-9d52-f11326c71234',
            'request_id' => '018d5f42-3a1d-7f90-9d52-f11326c79998',
            'ip_address' => '127.0.0.1',
            'user_agent_hash' => hash('sha256', 'test-agent'),
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'event_payload' => $eventPayload,
            'previous_hash' => $previousHash,
            'entry_hash' => $entryHash,
        ]);

        return $log;
    }
}
