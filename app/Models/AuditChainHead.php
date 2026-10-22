<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditChainHead extends Model
{
    protected $primaryKey = 'chain_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'chain_id',
        'last_event_uuid',
        'last_hash',
        'event_count',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'event_count' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'chain_id', 'chain_id');
    }
}
