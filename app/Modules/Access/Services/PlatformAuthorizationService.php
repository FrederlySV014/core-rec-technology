<?php

namespace App\Modules\Access\Services;

use App\Models\User;
use App\Modules\Access\Enums\PermissionScope;
use App\Modules\Identity\Enums\UserStatus;

class PlatformAuthorizationService
{
    /**
     * Determine whether the user is an active platform operator (holds any active platform role).
     */
    public function isOperator(User $user): bool
    {
        if ($user->status !== UserStatus::Active) {
            return false;
        }

        return $user->platformRoles()
            ->wherePivotNull('suspended_at')
            ->exists();
    }

    /**
     * Determine whether the user has a specific platform permission.
     * Platform permissions are strictly scoped to PermissionScope::Platform.
     */
    public function can(User $user, string $permissionSlug): bool
    {
        if ($user->status !== UserStatus::Active) {
            return false;
        }

        return $user->platformRoles()
            ->wherePivotNull('suspended_at')
            ->whereHas('permissions', function ($query) use ($permissionSlug) {
                $query->where('slug', $permissionSlug)
                    ->where('scope', PermissionScope::Platform->value);
            })
            ->exists();
    }
}
