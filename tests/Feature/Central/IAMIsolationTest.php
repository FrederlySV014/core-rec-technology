<?php

use App\Models\User;
use App\Modules\Access\Enums\PermissionScope;
use App\Modules\Access\Models\CompanyRole;
use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\PlatformRole;
use App\Modules\Access\Models\RoleTemplate;
use App\Modules\Companies\Models\Company;

describe('IAM Scopes & Platform vs Company Isolation', function () {
    test('assigning platform role to user does not create company memberships or commercial roles', function () {
        $user = User::factory()->create();

        $platformRole = PlatformRole::create([
            'name' => 'Superadministrador',
            'slug' => 'super_admin',
        ]);

        $user->platformRoles()->attach($platformRole->id);

        expect($user->platformRoles)->toHaveCount(1);
        expect($user->memberships)->toHaveCount(0); // Zero commercial access
    });

    test('permissions have strict scopes for platform and company', function () {
        $platformPerm = Permission::create([
            'scope' => PermissionScope::Platform,
            'name' => 'Ver Empresas',
            'slug' => 'platform.companies.view',
        ]);

        $companyPerm = Permission::create([
            'scope' => PermissionScope::Company,
            'name' => 'Ver Ventas',
            'slug' => 'sales.orders.view',
        ]);

        expect($platformPerm->scope)->toBe(PermissionScope::Platform);
        expect($companyPerm->scope)->toBe(PermissionScope::Company);
    });

    test('role templates exist as global blueprints and are separate from company roles', function () {
        $template = RoleTemplate::create([
            'name' => 'Plantilla Vendedor',
            'slug' => 'tmpl_sales_seller',
        ]);

        $company = Company::create([
            'legal_name' => 'Comercio Real SAC',
            'country_code' => 'PE',
            'tax_id_type' => 'RUC',
            'tax_id' => '20777777777',
            'slug' => 'comercio-real',
            'contact_email' => 'real@example.com',
        ]);

        // Creating a company role based on template
        $companyRole = CompanyRole::create([
            'company_id' => $company->id,
            'name' => $template->name,
            'slug' => 'vendedor',
        ]);

        expect($companyRole->company_id)->toBe($company->id);
        expect(RoleTemplate::count())->toBe(1);
        expect($company->roles)->toHaveCount(1);
    });
});
