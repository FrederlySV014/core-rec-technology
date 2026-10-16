<?php

namespace App\Modules\Licensing\DTOs;

use App\Modules\Licensing\Enums\LicenseValidityStatus;
use App\Modules\Licensing\Models\License;

readonly class EffectiveLicenseResult
{
    /**
     * @param  list<string>  $enabledModuleSlugs
     */
    public function __construct(
        public LicenseValidityStatus $validityStatus,
        public ?License $license = null,
        public array $enabledModuleSlugs = [],
        public bool $isReadOnly = false,
    ) {}

    public function hasCommercialAccess(): bool
    {
        return in_array($this->validityStatus, [
            LicenseValidityStatus::Active,
            LicenseValidityStatus::GracePeriod,
        ], true);
    }

    public function isModuleEnabled(string $moduleSlug): bool
    {
        if (! $this->hasCommercialAccess()) {
            return false;
        }

        return in_array($moduleSlug, $this->enabledModuleSlugs, true);
    }
}
