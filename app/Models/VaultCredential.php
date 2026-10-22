<?php

declare(strict_types=1);

namespace App\Models;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VaultCredential extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'title',
        'category',
        'website_url',
        'encrypted_payload',
        'nonce',
        'auth_tag',
        'key_version',
        'payload_version',
        'owner_id',
        'updated_by',
        'lock_version',
    ];

    protected $hidden = [
        'encrypted_payload',
        'nonce',
        'auth_tag',
    ];

    protected function casts(): array
    {
        return [
            'key_version' => 'integer',
            'payload_version' => 'integer',
            'lock_version' => 'integer',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function accesses(): HasMany
    {
        return $this->hasMany(CredentialAccess::class);
    }

    /**
     * Restrict an index query to credentials whose metadata the user may see.
     * A policy protects individual records; this scope prevents list leakage.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $role = $user->getAttribute('role');
        $role = $role instanceof BackedEnum ? $role->value : (string) $role;

        if (in_array(strtolower($role), ['admin', 'system_administrator'], true)) {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($user, $role): void {
            if (in_array(strtolower($role), ['manager', 'executive'], true)) {
                $visible->where('owner_id', $user->getKey());
            } else {
                $visible->whereRaw('1 = 0');
            }

            $visible->orWhereHas('accesses', function (Builder $access) use ($user): void {
                $access->where('user_id', $user->getKey())
                    ->where('can_view_metadata', true)
                    ->whereNull('revoked_at')
                    ->where(function (Builder $expiry): void {
                        $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    });
            });
        });
    }
}
