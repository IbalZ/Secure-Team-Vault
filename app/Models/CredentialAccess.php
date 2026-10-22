<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CredentialAccess extends Model
{
    use HasFactory;

    public const PERMISSIONS = [
        'can_view_metadata',
        'can_reveal_secret',
        'can_edit',
        'can_manage_access',
    ];

    protected $fillable = [
        'vault_credential_id',
        'user_id',
        'can_view_metadata',
        'can_reveal_secret',
        'can_edit',
        'can_manage_access',
        'granted_by',
        'revoked_by',
        'expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'can_view_metadata' => 'boolean',
            'can_reveal_secret' => 'boolean',
            'can_edit' => 'boolean',
            'can_manage_access' => 'boolean',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(VaultCredential::class, 'vault_credential_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(function (Builder $expiry): void {
                $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function allows(string $permission): bool
    {
        if (! in_array($permission, self::PERMISSIONS, true)) {
            return false;
        }

        return $this->isActive() && (bool) $this->getAttribute($permission);
    }
}
