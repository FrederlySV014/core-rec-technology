<?php

use App\Models\User;
use App\Modules\Access\Models\CompanyRole;
use App\Modules\Access\Models\MembershipRole;
use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Models\Membership;
use Illuminate\Database\QueryException;

describe('Central Schema Integrity & Isolation', function () {
    test('enforces unique tax identity per company (country, tax_id_type, tax_id)', function () {
        Company::create([
            'legal_name' => 'Empresa Uno SAC',
            'country_code' => 'PE',
            'tax_id_type' => 'RUC',
            'tax_id' => '20123456789',
            'slug' => 'empresa-uno',
            'contact_email' => 'uno@example.com',
        ]);

        expect(fn () => Company::create([
            'legal_name' => 'Empresa Uno Clon SAC',
            'country_code' => 'PE',
            'tax_id_type' => 'RUC',
            'tax_id' => '20123456789',
            'slug' => 'empresa-uno-clon',
            'contact_email' => 'clon@example.com',
        ]))->toThrow(QueryException::class);
    });

    test('prevents duplicate memberships for the same user in the same company', function () {
        $user = User::factory()->create();
        $company = Company::create([
            'legal_name' => 'Comercial SAC',
            'country_code' => 'PE',
            'tax_id_type' => 'RUC',
            'tax_id' => '20987654321',
            'slug' => 'comercial-sac',
            'contact_email' => 'info@comercial.com',
        ]);

        Membership::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        expect(fn () => Membership::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]))->toThrow(QueryException::class);
    });

    test('physically rejects assigning a company role from company B to a membership of company A via composite FK', function () {
        $companyA = Company::create([
            'legal_name' => 'Empresa A SAC',
            'country_code' => 'PE',
            'tax_id_type' => 'RUC',
            'tax_id' => '20111111111',
            'slug' => 'empresa-a',
            'contact_email' => 'a@example.com',
        ]);

        $companyB = Company::create([
            'legal_name' => 'Empresa B SAC',
            'country_code' => 'PE',
            'tax_id_type' => 'RUC',
            'tax_id' => '20222222222',
            'slug' => 'empresa-b',
            'contact_email' => 'b@example.com',
        ]);

        $user = User::factory()->create();

        // Membership in Company A
        $membershipA = Membership::create([
            'company_id' => $companyA->id,
            'user_id' => $user->id,
        ]);

        // Role created in Company B
        $roleB = CompanyRole::create([
            'company_id' => $companyB->id,
            'name' => 'Vendedor B',
            'slug' => 'vendedor-b',
        ]);

        // Attempting to assign Role B to Membership A must be rejected by foreign key constraint
        expect(fn () => MembershipRole::create([
            'company_id' => $companyA->id,
            'membership_id' => $membershipA->id,
            'company_role_id' => $roleB->id, // Belongs to Company B!
        ]))->toThrow(QueryException::class);
    });
});
