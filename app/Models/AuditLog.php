<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

class AuditLog extends Model
{
    public $timestamps = false;

    /** Preserve the microseconds included in the HMAC-protected event time. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'event_uuid',
        'chain_id',
        'occurred_at',
        'actor_user_id',
        'actor_role',
        'action',
        'target_type',
        'target_id',
        'request_id',
        'ip_address',
        'user_agent_hash',
        'metadata',
        'event_payload',
        'previous_hash',
        'entry_hash',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'actor_user_id' => 'integer',
        ];
    }

    /** Audit DATETIME values are stored in UTC, not the application's display timezone. */
    protected function asDateTime($value): Carbon
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->utc();
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestampUTC($value);
        }

        return Carbon::parse($value, 'UTC')->utc();
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Audit log entries are append-only and cannot be updated.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Audit log entries are append-only and cannot be deleted.');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function chain(): BelongsTo
    {
        return $this->belongsTo(AuditChainHead::class, 'chain_id', 'chain_id');
    }

    public function metadataArray(): array
    {
        $decoded = json_decode($this->metadata, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
