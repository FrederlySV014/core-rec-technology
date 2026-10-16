<?php

namespace App\Modules\Access\Services;

use App\Models\User;
use App\Modules\Access\DTOs\CompanyContext;
use App\Modules\Companies\Enums\CompanyStatus;
use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\Membership;
use App\Modules\Licensing\Enums\LicenseValidityStatus;
use App\Modules\Licensing\Services\LicenseResolverService;

class CompanyAuthorizationService
{
    public function __construct(
        protected LicenseResolverService $licenseResolver
    ) {}

    /**
     * Resolve the complete authorization and licensing context for a user in a company.
     * Evaluated strictly on the current request without global session pollution.
     */
    public function resolveContext(User $user, Company $company): ?CompanyContext
    {
        if ($user->status !== UserStatus::Active) {
            return null;
        }

        if ($company->administrative_status !== CompanyStatus::Active) {
            return null;
        }

        /** @var Membership|null $membership */
        $membership = $company->memberships()
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->first();

        if ($membership === null) {
            return null;
        }

        $effectiveLicense = $this->licenseResolver->resolveEffectiveLicense($company);
        $enabledModules = $effectiveLicense->enabledModuleSlugs;

        // Fetch company roles and their permissions for this membership
        $roles = $membership->roles()->with(['permissions.module'])->get();

        $effectivePermissionSlugs = [];
        foreach ($roles as $role) {
            foreach ($role->permissions as $permission) {
                // Identity and security administrative permissions are retained for service status & access control
                $isAdministrativeSecurity = in_array($permission->slug, [
                    'commercial.members.manage',
                    'commercial.company.view',
                ], true);

                // If permission is coupled to a commercial module, ensure that module is enabled in the license
                if (! $isAdministrativeSecurity && $permission->module_id !== null) {
                    $moduleSlug = $permission->module?->slug;
                    if ($moduleSlug !== null && ! in_array($moduleSlug, $enabledModules, true)) {
                        continue;
                    }
                }

                $effectivePermissionSlugs[] = $permission->slug;
            }
        }

        /** @var list<string> $uniquePermissions */
        $uniquePermissions = array_values(array_unique($effectivePermissionSlugs));

        return new CompanyContext(
            company: $company,
            membership: $membership,
            effectiveLicense: $effectiveLicense,
            enabledModuleSlugs: $enabledModules,
            effectivePermissionSlugs: $uniquePermissions,
            isReadOnly: $effectiveLicense->isReadOnly
        );
    }

    /**
     * Determine whether an action/permission is mutable or read-only.
     */
    public function isActionMutable(string $permissionSlug, ?bool $explicitMutable = null): bool
    {
        if ($explicitMutable !== null) {
            return $explicitMutable;
        }

        // Read-only permission conventions
        $readOnlySuffixes = ['.view', '.read', '.query', '.export', '.list', '.show'];
        foreach ($readOnlySuffixes as $suffix) {
            if (str_ends_with($permissionSlug, $suffix)) {
                return false;
            }
        }

        // Mutable actions by default
        return true;
    }

    /**
     * Determine whether the given commercial action is authorized within the company context.
     */
    public function can(CompanyContext $context, string $permissionSlug, ?bool $isMutable = null): bool
    {
        // 1. License must be currently valid (Active or GracePeriod)
        $validity = $context->effectiveLicense->validityStatus;
        if ($validity !== LicenseValidityStatus::Active && $validity !== LicenseValidityStatus::GracePeriod) {
            return false;
        }

        // 2. User must possess the effective permission within this company
        if (! $context->hasPermission($permissionSlug)) {
            return false;
        }

        // 3. Grace period read-only enforcement
        if ($context->isReadOnly) {
            $mutable = $this->isActionMutable($permissionSlug, $isMutable);
            if ($mutable) {
                return false;
            }
        }

        return true;
    }
}
