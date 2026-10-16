<?php

use App\Modules\Companies\Models\Company;
use App\Modules\Licensing\Enums\LicenseAdministrativeStatus;
use App\Modules\Licensing\Enums\LicenseValidityStatus;
use App\Modules\Licensing\Exceptions\LicenseOverlapException;
use App\Modules\Licensing\Models\License;
use App\Modules\Licensing\Models\Module;
use App\Modules\Licensing\Services\LicenseResolverService;
use Illuminate\Support\Carbon;

describe('Licensing Resolution & Grace Period', function () {
    beforeEach(function () {
        $this->service = app(LicenseResolverService::class);
        $this->company = Company::create([
            'legal_name' => 'Tech Corp SAC',
            'country_code' => 'PE',
            'tax_id_type' => 'RUC',
            'tax_id' => '20555555555',
            'slug' => 'tech-corp',
            'contact_email' => 'corp@example.com',
        ]);
    });

    test('prevents overlapping active licenses including grace period coverage', function () {
        // License 1: [2026-01-01, 2026-06-01) with 5 days grace -> covers until 2026-06-06
        License::create([
            'company_id' => $this->company->id,
            'license_key' => 'LIC-001',
            'plan_name' => 'Plan Inicial',
            'administrative_status' => LicenseAdministrativeStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
            'expires_at' => Carbon::parse('2026-06-01 00:00:00'),
            'grace_period_days' => 5,
        ]);

        // Attempting to create a license starting at 2026-06-03 (inside the grace period of Lic 1) must be rejected
        expect(fn () => $this->service->validateNoOverlap(
            $this->company,
            Carbon::parse('2026-06-03 00:00:00'),
            Carbon::parse('2026-12-31 00:00:00'),
            5
        ))->toThrow(LicenseOverlapException::class);
    });

    test('allows consecutive non-overlapping licenses', function () {
        // License 1: [2026-01-01, 2026-06-01) with 0 grace days
        License::create([
            'company_id' => $this->company->id,
            'license_key' => 'LIC-001-CONSEC',
            'plan_name' => 'Plan Inicial',
            'administrative_status' => LicenseAdministrativeStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
            'expires_at' => Carbon::parse('2026-06-01 00:00:00'),
            'grace_period_days' => 0,
        ]);

        // License 2 starts exactly when License 1 ends: 2026-06-01 (half-open [starts, ends) interval)
        expect(fn () => $this->service->validateNoOverlap(
            $this->company,
            Carbon::parse('2026-06-01 00:00:00'),
            Carbon::parse('2026-12-31 00:00:00'),
            0
        ))->not->toThrow(LicenseOverlapException::class);
    });

    test('adjusts previous grace period when renewal starts during grace period', function () {
        $previousLicense = License::create([
            'company_id' => $this->company->id,
            'license_key' => 'LIC-PREV',
            'plan_name' => 'Plan Anterior',
            'administrative_status' => LicenseAdministrativeStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
            'expires_at' => Carbon::parse('2026-06-01 00:00:00'),
            'grace_period_days' => 10, // original grace ends on 2026-06-11
        ]);

        // Renewal starts on 2026-06-04 (during grace period)
        $renewalStart = Carbon::parse('2026-06-04 00:00:00');

        $adjusted = $this->service->adjustPreviousGracePeriodForRenewal($previousLicense, $renewalStart);

        // Grace period should be shortened to 3 days (from 2026-06-01 to 2026-06-04)
        expect($adjusted->grace_period_days)->toBe(3);

        // Now validateNoOverlap must pass with the adjusted license
        expect(fn () => $this->service->validateNoOverlap(
            $this->company,
            $renewalStart,
            Carbon::parse('2026-12-31 00:00:00'),
            5
        ))->not->toThrow(LicenseOverlapException::class);
    });

    test('correctly distinguishes active status, grace period read-only and expired status based on timestamps', function () {
        $license = License::create([
            'company_id' => $this->company->id,
            'license_key' => 'LIC-FLOW',
            'plan_name' => 'Plan Flujo',
            'administrative_status' => LicenseAdministrativeStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
            'expires_at' => Carbon::parse('2026-06-01 00:00:00'),
            'grace_period_days' => 5, // grace period is 2026-06-01 to 2026-06-06
        ]);

        // 1. Regular active window
        $resultRegular = $this->service->resolveEffectiveLicense(
            $this->company,
            Carbon::parse('2026-03-15 12:00:00')
        );
        expect($resultRegular->validityStatus)->toBe(LicenseValidityStatus::Active);
        expect($resultRegular->isReadOnly)->toBeFalse();
        expect($resultRegular->hasCommercialAccess())->toBeTrue();

        // 2. During grace period
        $resultGrace = $this->service->resolveEffectiveLicense(
            $this->company,
            Carbon::parse('2026-06-03 10:00:00')
        );
        expect($resultGrace->validityStatus)->toBe(LicenseValidityStatus::GracePeriod);
        expect($resultGrace->isReadOnly)->toBeTrue();
        expect($resultGrace->hasCommercialAccess())->toBeTrue();

        // 3. Beyond grace period (Expired)
        $resultExpired = $this->service->resolveEffectiveLicense(
            $this->company,
            Carbon::parse('2026-06-07 00:00:00')
        );
        expect($resultExpired->validityStatus)->toBe(LicenseValidityStatus::Expired);
        expect($resultExpired->hasCommercialAccess())->toBeFalse();
    });

    test('supports indefinite licenses without expiration date', function () {
        License::create([
            'company_id' => $this->company->id,
            'license_key' => 'LIC-PERPETUAL',
            'plan_name' => 'Licencia Perpetua',
            'administrative_status' => LicenseAdministrativeStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
            'expires_at' => null, // Indefinite
            'grace_period_days' => 0,
        ]);

        $result = $this->service->resolveEffectiveLicense(
            $this->company,
            Carbon::parse('2035-12-31 23:59:59')
        );

        expect($result->validityStatus)->toBe(LicenseValidityStatus::Active);
        expect($result->isReadOnly)->toBeFalse();
        expect($result->license->isIndefinite())->toBeTrue();
    });

    test('removing a module from a license disables access to that module without deleting records', function () {
        $moduleSales = Module::create(['name' => 'Ventas', 'slug' => 'sales', 'status' => 'active']);
        $moduleBilling = Module::create(['name' => 'Facturación', 'slug' => 'sunat_billing', 'status' => 'active']);

        $license = License::create([
            'company_id' => $this->company->id,
            'license_key' => 'LIC-MODULES',
            'plan_name' => 'Plan Modular',
            'administrative_status' => LicenseAdministrativeStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
            'expires_at' => Carbon::parse('2026-12-31 00:00:00'),
        ]);

        $license->modules()->attach([$moduleSales->id, $moduleBilling->id]);

        $now = Carbon::parse('2026-05-01 00:00:00');
        expect($this->service->isModuleEnabled($this->company, 'sales', $now))->toBeTrue();
        expect($this->service->isModuleEnabled($this->company, 'sunat_billing', $now))->toBeTrue();

        // Detach billing module (downgrade)
        $license->modules()->detach($moduleBilling->id);

        expect($this->service->isModuleEnabled($this->company, 'sales', $now))->toBeTrue();
        expect($this->service->isModuleEnabled($this->company, 'sunat_billing', $now))->toBeFalse();
        // Module itself still exists in central catalog
        expect(Module::where('slug', 'sunat_billing')->exists())->toBeTrue();
    });
});
