<?php

namespace App\Modules\Access\Services;

use App\Models\User;
use App\Modules\Companies\Enums\CompanyStatus;
use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Models\Membership;

class PrivilegeService
{
    /**
     * Administrative company permission prefixes/slugs that grant access-management authority.
     */
    public const PRIVILEGED_COMPANY_PERMISSIONS = [
        'commercial.members.manage',
        'commercial.members.invite',
        'commercial.members.assign_roles',
        'commercial.members.suspend',
        'commercial.members.reactivate',
        'commercial.members.revoke',
        'commercial.roles.create',
        'commercial.roles.update',
        'commercial.roles.assign_permissions',
        'commercial.roles.delete',
        'commercial.ownership.transfer',
    ];

    public function __construct(
        protected PlatformAuthorizationService $platformAuth
    ) {}

    /**
     * Check if the user has any active SaaS platform roles.
     */
    public function isPlatformPrivileged(User $user): bool
    {
        return $this->platformAuth->isOperator($user);
    }

    /**
     * Check if the user is a privileged member in the given company (or in ANY active company if null).
     */
    public function isCompanyPrivileged(User $user, ?Company $company = null): bool
    {
        $query = Membership::query()
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->whereHas('company', fn ($q) => $q->where('administrative_status', CompanyStatus::Active));

        if ($company !== null) {
            $query->where('company_id', $company->id);
        }

        $memberships = $query->with('roles.permissions')->get();

        foreach ($memberships as $membership) {
            if ($membership->is_owner) {
                return true;
            }

            foreach ($membership->roles as $role) {
                foreach ($role->permissions as $permission) {
                    if (in_array($permission->slug, self::PRIVILEGED_COMPANY_PERMISSIONS, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if the user holds privileged attributes in platform or company scope.
     */
    public function isPrivileged(User $user, ?Company $company = null): bool
    {
        return $this->isPlatformPrivileged($user) || $this->isCompanyPrivileged($user, $company);
    }

    /**
     * Verify whether the user has two-factor authentication confirmed and has cleared the challenge in the current session.
     */
    public function hasClearedTwoFactor(User $user): bool
    {
        if (! $user->hasEnabledTwoFactorAuthentication() || $user->two_factor_confirmed_at === null) {
            return false;
        }

        // Must have passed the 2FA challenge in the current session
        return session('auth.2fa_passed') === true;
    }
}
