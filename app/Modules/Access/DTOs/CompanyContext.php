<?php

namespace App\Modules\Access\DTOs;

use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Models\Membership;
use App\Modules\Licensing\DTOs\EffectiveLicenseResult;

class CompanyContext
{
    /**
     * @param  list<string>  $enabledModuleSlugs
     * @param  list<string>  $effectivePermissionSlugs
     */
    public function __construct(
        public readonly Company $company,
        public readonly Membership $membership,
        public readonly EffectiveLicenseResult $effectiveLicense,
        public readonly array $enabledModuleSlugs,
        public readonly array $effectivePermissionSlugs,
        public readonly bool $isReadOnly
    ) {}

    /**
     * Determine whether the user possesses the given commercial permission in this company.
     */
    public function hasPermission(string $permissionSlug): bool
    {
        if (in_array($permissionSlug, $this->effectivePermissionSlugs, true)) {
            return true;
        }

        // Backward compatibility: legacy monolithic 'commercial.members.manage' grants member & role permissions (excluding ownership transfer)
        if ($permissionSlug !== 'commercial.ownership.transfer' &&
            (str_starts_with($permissionSlug, 'commercial.members.') || str_starts_with($permissionSlug, 'commercial.roles.'))) {
            return in_array('commercial.members.manage', $this->effectivePermissionSlugs, true);
        }

        return false;
    }

    /**
     * Determine whether a specific module is licensed and enabled for this company.
     */
    public function isModuleEnabled(string $moduleSlug): bool
    {
        return in_array($moduleSlug, $this->enabledModuleSlugs, true);
    }
}
