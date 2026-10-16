<?php

namespace App\Modules\Licensing\Services;

use App\Modules\Companies\Models\Company;
use App\Modules\Licensing\DTOs\EffectiveLicenseResult;
use App\Modules\Licensing\Enums\LicenseAdministrativeStatus;
use App\Modules\Licensing\Enums\LicenseValidityStatus;
use App\Modules\Licensing\Exceptions\LicenseOverlapException;
use App\Modules\Licensing\Models\License;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class LicenseResolverService
{
    /**
     * Validate that a proposed license interval [startsAt, effectiveEnd) does not overlap
     * with any existing active or suspended licenses for the same company.
     *
     * @throws LicenseOverlapException
     */
    public function validateNoOverlap(
        Company $company,
        CarbonInterface $startsAt,
        ?CarbonInterface $expiresAt,
        int $gracePeriodDays = 0,
        ?int $excludeLicenseId = null
    ): void {
        $proposedStart = Carbon::instance($startsAt);
        $proposedEnd = $expiresAt !== null
            ? Carbon::instance($expiresAt)->addDays($gracePeriodDays)
            : null;

        if ($proposedEnd !== null && $proposedEnd->lessThanOrEqualTo($proposedStart)) {
            throw new LicenseOverlapException('License expiration date including grace period must be strictly greater than start date.');
        }

        $existingLicenses = $company->licenses()
            ->whereIn('administrative_status', [
                LicenseAdministrativeStatus::Active->value,
                LicenseAdministrativeStatus::Suspended->value,
            ])
            ->when($excludeLicenseId !== null, fn ($query) => $query->where('id', '!=', $excludeLicenseId))
            ->get();

        foreach ($existingLicenses as $existing) {
            $existingStart = Carbon::instance($existing->starts_at);
            $existingEnd = $existing->effectiveEndsAt();

            // Interval overlap check for half-open intervals [A, B) and [C, D):
            // Two intervals overlap if: A < D and C < B.
            $startsBeforeExistingEnds = $existingEnd === null || $proposedStart->lessThan($existingEnd);
            $existingStartsBeforeProposedEnds = $proposedEnd === null || $existingStart->lessThan($proposedEnd);

            if ($startsBeforeExistingEnds && $existingStartsBeforeProposedEnds) {
                throw new LicenseOverlapException(sprintf(
                    'Proposed license interval [%s, %s) overlaps with existing license %s [%s, %s).',
                    $proposedStart->toIso8601String(),
                    $proposedEnd?->toIso8601String() ?? 'infinite',
                    $existing->license_key,
                    $existingStart->toIso8601String(),
                    $existingEnd?->toIso8601String() ?? 'infinite'
                ));
            }
        }
    }

    /**
     * If a renewal starts during a previous license's grace period, adjust the previous
     * license's grace period so it ends exactly when the new license starts.
     */
    public function adjustPreviousGracePeriodForRenewal(License $previousLicense, CarbonInterface $renewalStartsAt): License
    {
        if ($previousLicense->expires_at === null) {
            return $previousLicense;
        }

        $renewalStart = Carbon::instance($renewalStartsAt);
        $previousExpiry = Carbon::instance($previousLicense->expires_at);
        $previousEffectiveEnd = $previousLicense->effectiveEndsAt();

        // Check if renewal starts within the grace period: [expires_at, effectiveEndsAt)
        if ($previousEffectiveEnd !== null
            && $renewalStart->greaterThanOrEqualTo($previousExpiry)
            && $renewalStart->lessThan($previousEffectiveEnd)) {

            $adjustedDays = (int) ceil($previousExpiry->diffInHours($renewalStart) / 24);
            $previousLicense->grace_period_days = max(0, $adjustedDays);
            $previousLicense->effective_ends_at = $renewalStart;
            $previousLicense->save();
        }

        return $previousLicense;
    }

    /**
     * Resolve the effective license and access level at a given timestamp.
     */
    public function resolveEffectiveLicense(Company $company, ?CarbonInterface $at = null): EffectiveLicenseResult
    {
        $targetTime = $at !== null ? Carbon::instance($at) : Carbon::now();

        $activeLicenses = $company->licenses()
            ->with(['modules' => fn ($query) => $query->where('status', 'active')])
            ->where('administrative_status', LicenseAdministrativeStatus::Active->value)
            ->get();

        foreach ($activeLicenses as $license) {
            $start = Carbon::instance($license->starts_at);
            $expiry = $license->expires_at !== null ? Carbon::instance($license->expires_at) : null;
            $effectiveEnd = $license->effectiveEndsAt();

            // Check if targetTime falls within [starts_at, effectiveEndsAt)
            $isStarted = $targetTime->greaterThanOrEqualTo($start);
            $isNotEnded = $effectiveEnd === null || $targetTime->lessThan($effectiveEnd);

            if ($isStarted && $isNotEnded) {
                /** @var list<string> $enabledModuleSlugs */
                $enabledModuleSlugs = array_values(array_map('strval', $license->modules->pluck('slug')->all()));

                // Check whether it is in regular active window or grace period
                if ($expiry === null || $targetTime->lessThan($expiry)) {
                    return new EffectiveLicenseResult(
                        validityStatus: LicenseValidityStatus::Active,
                        license: $license,
                        enabledModuleSlugs: $enabledModuleSlugs,
                        isReadOnly: false
                    );
                }

                // In grace period: read-only access allowed
                return new EffectiveLicenseResult(
                    validityStatus: LicenseValidityStatus::GracePeriod,
                    license: $license,
                    enabledModuleSlugs: $enabledModuleSlugs,
                    isReadOnly: true
                );
            }
        }

        // If no matching current window, determine why: future vs expired
        $hasFuture = $activeLicenses->contains(fn ($l) => $targetTime->lessThan($l->starts_at));
        if ($hasFuture) {
            return new EffectiveLicenseResult(LicenseValidityStatus::Future);
        }

        $hasExpired = $activeLicenses->contains(fn ($l) => $l->effectiveEndsAt() !== null && $targetTime->greaterThanOrEqualTo($l->effectiveEndsAt()));
        if ($hasExpired) {
            return new EffectiveLicenseResult(LicenseValidityStatus::Expired);
        }

        return new EffectiveLicenseResult(LicenseValidityStatus::None);
    }

    /**
     * Determine whether a specific commercial module is enabled for the company at a given timestamp.
     */
    public function isModuleEnabled(Company $company, string $moduleSlug, ?CarbonInterface $at = null): bool
    {
        return $this->resolveEffectiveLicense($company, $at)->isModuleEnabled($moduleSlug);
    }
}
