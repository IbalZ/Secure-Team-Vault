<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\VaultCredential;
use BackedEnum;

class VaultCredentialPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if (! $this->isActive($user)) {
            return false;
        }

        return $this->hasRole($user, 'admin', 'system_administrator') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->isActive($user);
    }

    public function create(User $user): bool
    {
        return $this->isActive($user) && $this->isManager($user);
    }

    public function view(User $user, VaultCredential $credential): bool
    {
        if ($this->isManager($user) && $credential->owner_id === $user->getKey()) {
            return true;
        }

        return $this->hasAccess($user, $credential, 'can_view_metadata');
    }

    public function reveal(User $user, VaultCredential $credential): bool
    {
        if ($this->isManager($user) && $credential->owner_id === $user->getKey()) {
            return true;
        }

        return $this->hasAccess($user, $credential, 'can_reveal_secret');
    }

    /** Copying is not a stronger boundary once plaintext may be revealed. */
    public function copy(User $user, VaultCredential $credential): bool
    {
        return $this->reveal($user, $credential);
    }

    public function update(User $user, VaultCredential $credential): bool
    {
        if (! $this->isManager($user)) {
            return false;
        }

        return $credential->owner_id === $user->getKey()
            || $this->hasAccess($user, $credential, 'can_edit');
    }

    public function delete(User $user, VaultCredential $credential): bool
    {
        return $this->isManager($user) && $credential->owner_id === $user->getKey();
    }

    public function restore(User $user, VaultCredential $credential): bool
    {
        return false;
    }

    public function forceDelete(User $user, VaultCredential $credential): bool
    {
        return false;
    }

    public function manageAccess(User $user, VaultCredential $credential): bool
    {
        if (! $this->isManager($user)) {
            return false;
        }

        return $credential->owner_id === $user->getKey()
            || $this->hasAccess($user, $credential, 'can_manage_access');
    }

    private function hasAccess(User $user, VaultCredential $credential, string $permission): bool
    {
        return $credential->accesses()
            ->active()
            ->where('user_id', $user->getKey())
            ->where($permission, true)
            ->exists();
    }

    private function isManager(User $user): bool
    {
        return $this->hasRole($user, 'manager', 'executive');
    }

    private function hasRole(User $user, string ...$roles): bool
    {
        $role = $user->getAttribute('role');
        $role = $role instanceof BackedEnum ? $role->value : (string) $role;

        return in_array(strtolower($role), $roles, true);
    }

    private function isActive(User $user): bool
    {
        if (method_exists($user, 'canAccessSystem')) {
            return $user->canAccessSystem();
        }

        return $user->getAttribute('is_active') !== false;
    }
}
