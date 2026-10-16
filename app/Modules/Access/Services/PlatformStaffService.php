<?php

namespace App\Modules\Access\Services;

use App\Models\User;
use App\Modules\Access\Models\PlatformRole;
use App\Modules\Audit\Models\CentralAuditLog;
use App\Modules\Identity\Enums\UserStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlatformStaffService
{
    /**
     * Ensure the given user is not the sole remaining active platform administrator.
     *
     * @throws ValidationException
     */
    public function ensureNotLastActiveAdmin(User $user): void
    {
        // Check if this user currently possesses active access management capacity
        $hasManageCapability = $user->platformRoles()
            ->wherePivotNull('suspended_at')
            ->whereHas('permissions', fn ($q) => $q->where('slug', 'platform.staff.manage'))
            ->exists();

        if (! $hasManageCapability) {
            return;
        }

        // Lock matching rows via pluck before count to serialize without aggregate FOR UPDATE error
        $otherActiveAdminIds = User::query()
            ->where('id', '!=', $user->id)
            ->where('status', UserStatus::Active)
            ->whereHas('platformRoles', function ($q) {
                $q->whereNull('platform_user_roles.suspended_at')
                    ->whereHas('permissions', fn ($pq) => $pq->where('slug', 'platform.staff.manage'));
            })
            ->lockForUpdate()
            ->pluck('id');

        if ($otherActiveAdminIds->isEmpty()) {
            throw ValidationException::withMessages([
                'user' => 'No es posible realizar esta operación: la plataforma no puede quedar sin ningún administrador activo con capacidad de gestionar accesos.',
            ]);
        }
    }

    /**
     * Add or update an operator with platform roles.
     *
     * @param  list<int>  $roleIds
     */
    public function addOrUpdateOperator(string $email, string $name, array $roleIds, ?User $actor = null): User
    {
        return DB::connection('central')->transaction(function () use ($email, $name, $roleIds, $actor) {
            $user = User::where('email', $email)->first();

            if (! $user) {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => bcrypt(Str::random(32)),
                    'status' => UserStatus::Active,
                ]);
            }

            $validRoleIds = PlatformRole::whereIn('id', $roleIds)->pluck('id')->all();

            $syncData = [];
            foreach ($validRoleIds as $rId) {
                $syncData[$rId] = [
                    'assigned_at' => now(),
                    'suspended_at' => null,
                ];
            }

            $user->platformRoles()->sync($syncData);

            CentralAuditLog::create([
                'user_id' => $actor?->id,
                'action' => 'platform.staff.roles_assigned',
                'auditable_type' => $user->getMorphClass(),
                'auditable_id' => (string) $user->id,
                'new_values' => [
                    'target_user_email' => $user->email,
                    'assigned_role_ids' => $validRoleIds,
                ],
                'created_at' => now(),
            ]);

            return $user;
        });
    }

    /**
     * Assign specific platform roles to an existing user.
     *
     * @param  list<int>  $roleIds
     */
    public function updateRoles(User $user, array $roleIds, ?User $actor = null): void
    {
        // If the update removes manage capacity from the last admin, guard against it
        $targetRolesHaveManage = PlatformRole::whereIn('id', $roleIds)
            ->whereHas('permissions', fn ($q) => $q->where('slug', 'platform.staff.manage'))
            ->exists();

        if (! $targetRolesHaveManage) {
            $this->ensureNotLastActiveAdmin($user);
        }

        DB::connection('central')->transaction(function () use ($user, $roleIds, $actor) {
            $validRoleIds = PlatformRole::whereIn('id', $roleIds)->pluck('id')->all();

            $syncData = [];
            foreach ($validRoleIds as $rId) {
                $syncData[$rId] = [
                    'assigned_at' => now(),
                    'suspended_at' => null,
                ];
            }

            $user->platformRoles()->sync($syncData);

            CentralAuditLog::create([
                'user_id' => $actor?->id,
                'action' => 'platform.staff.roles_updated',
                'auditable_type' => $user->getMorphClass(),
                'auditable_id' => (string) $user->id,
                'new_values' => [
                    'target_user_email' => $user->email,
                    'assigned_role_ids' => $validRoleIds,
                ],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Suspend platform access for the user without affecting global status or company memberships.
     */
    public function suspendAccess(User $user, ?User $actor = null): void
    {
        $this->ensureNotLastActiveAdmin($user);

        DB::connection('central')->transaction(function () use ($user, $actor) {
            DB::connection('central')->table('platform_user_roles')
                ->where('user_id', $user->id)
                ->update(['suspended_at' => now()]);

            CentralAuditLog::create([
                'user_id' => $actor?->id,
                'action' => 'platform.staff.access_suspended',
                'auditable_type' => $user->getMorphClass(),
                'auditable_id' => (string) $user->id,
                'new_values' => [
                    'target_user_email' => $user->email,
                ],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Reactivate previously suspended platform access for the user.
     */
    public function reactivateAccess(User $user, ?User $actor = null): void
    {
        DB::connection('central')->transaction(function () use ($user, $actor) {
            DB::connection('central')->table('platform_user_roles')
                ->where('user_id', $user->id)
                ->update(['suspended_at' => null]);

            CentralAuditLog::create([
                'user_id' => $actor?->id,
                'action' => 'platform.staff.access_reactivated',
                'auditable_type' => $user->getMorphClass(),
                'auditable_id' => (string) $user->id,
                'new_values' => [
                    'target_user_email' => $user->email,
                ],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Revoke all platform roles for the user, removing SaaS access completely while preserving global account and memberships.
     */
    public function revokeAccess(User $user, ?User $actor = null): void
    {
        $this->ensureNotLastActiveAdmin($user);

        DB::connection('central')->transaction(function () use ($user, $actor) {
            $user->platformRoles()->detach();

            CentralAuditLog::create([
                'user_id' => $actor?->id,
                'action' => 'platform.staff.access_revoked',
                'auditable_type' => $user->getMorphClass(),
                'auditable_id' => (string) $user->id,
                'new_values' => [
                    'target_user_email' => $user->email,
                ],
                'created_at' => now(),
            ]);
        });
    }
}
