<?php

namespace App\Modules\Access\Services;

use App\Models\User;
use App\Modules\Access\Enums\PermissionScope;
use App\Modules\Access\Models\CompanyRole;
use App\Modules\Access\Models\Permission;
use App\Modules\Audit\Models\CentralAuditLog;
use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Licensing\Enums\LicenseValidityStatus;
use App\Modules\Licensing\Services\LicenseResolverService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyRoleService
{
    /**
     * Reserved system role slugs that cannot be created or deleted as custom roles.
     *
     * @var list<string>
     */
    protected const RESERVED_SLUGS = [
        'company_owner',
        'company_admin',
    ];

    public function __construct(
        protected CompanyAuthorizationService $companyAuth,
        protected LicenseResolverService $licenseResolver
    ) {}

    /**
     * Get all company roles with their permissions.
     *
     * @return Collection<int, CompanyRole>
     */
    public function listRoles(Company $company): Collection
    {
        return CompanyRole::where('company_id', $company->id)
            ->with(['permissions', 'memberships'])
            ->orderBy('is_system', 'desc')
            ->orderBy('name')
            ->get();
    }

    /**
     * Create a new custom role for the given company.
     *
     * @param  list<int>  $permissionIds
     */
    public function createRole(Company $company, string $name, string $slug, ?string $description, array $permissionIds, User $actor): CompanyRole
    {
        $licenseResult = $this->licenseResolver->resolveEffectiveLicense($company);
        if ($licenseResult->validityStatus === LicenseValidityStatus::Expired) {
            throw ValidationException::withMessages([
                'license' => 'No es posible crear roles mientras la licencia de la empresa se encuentre vencida.',
            ]);
        }

        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            throw ValidationException::withMessages([
                'slug' => 'El identificador ingresado está reservado para roles del sistema.',
            ]);
        }

        $existing = CompanyRole::where('company_id', $company->id)
            ->where('slug', $slug)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'slug' => 'Ya existe un rol con este identificador en la empresa.',
            ]);
        }

        // Strictly sanitize permissions: ONLY Company scope allowed (prevents privilege escalation to platform)
        $validPermissionIds = Permission::where('scope', PermissionScope::Company)
            ->whereIn('id', $permissionIds)
            ->pluck('id')
            ->all();

        // Enforce privilege delegation boundary: actor cannot delegate permissions they do not possess
        $actorContext = $this->companyAuth->resolveContext($actor, $company);
        $actorPermissions = $actorContext ? $actorContext->effectivePermissionSlugs : [];
        $requestedPermissionSlugs = Permission::whereIn('id', $validPermissionIds)->pluck('slug')->all();
        $unauthorized = array_diff($requestedPermissionSlugs, $actorPermissions);

        if (! empty($unauthorized)) {
            throw ValidationException::withMessages([
                'permissions' => 'No tiene autorización para delegar permisos que no posee ('.implode(', ', $unauthorized).').',
            ]);
        }

        // Ownership transfer permission is reserved strictly to the owner
        if (in_array('commercial.ownership.transfer', $requestedPermissionSlugs, true)) {
            $actorMembership = $company->memberships()->where('user_id', $actor->id)->where('status', MembershipStatus::Active)->first();
            if (! $actorMembership || ! $actorMembership->is_owner) {
                throw ValidationException::withMessages([
                    'permissions' => 'El permiso de transferencia de titularidad está reservado exclusivamente para el propietario.',
                ]);
            }
        }

        return DB::connection('central')->transaction(function () use ($company, $name, $slug, $description, $validPermissionIds, $actor) {
            $role = CompanyRole::create([
                'company_id' => $company->id,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'is_system' => false,
            ]);

            $role->permissions()->sync($validPermissionIds);

            CentralAuditLog::create([
                'user_id' => $actor->id,
                'company_id' => $company->id,
                'action' => 'company.role.created',
                'auditable_type' => $role->getMorphClass(),
                'auditable_id' => (string) $role->id,
                'new_values' => [
                    'name' => $name,
                    'slug' => $slug,
                    'permissions' => $validPermissionIds,
                ],
                'created_at' => now(),
            ]);

            return $role;
        });
    }

    /**
     * Update an existing custom company role.
     *
     * @param  list<int>  $permissionIds
     */
    public function updateRole(Company $company, CompanyRole $role, string $name, ?string $description, array $permissionIds, User $actor): CompanyRole
    {
        $licenseResult = $this->licenseResolver->resolveEffectiveLicense($company);
        if ($licenseResult->validityStatus === LicenseValidityStatus::Expired) {
            throw ValidationException::withMessages([
                'license' => 'No es posible modificar roles mientras la licencia de la empresa se encuentre vencida.',
            ]);
        }

        if ($role->company_id !== $company->id) {
            throw ValidationException::withMessages([
                'role' => 'El rol no pertenece a la empresa seleccionada.',
            ]);
        }

        // Strictly sanitize permissions: ONLY Company scope allowed
        $validPermissionIds = Permission::where('scope', PermissionScope::Company)
            ->whereIn('id', $permissionIds)
            ->pluck('id')
            ->all();

        // Protect company_owner system role from being edited by non-owners
        if ($role->slug === 'company_owner') {
            $actorMembership = $company->memberships()->where('user_id', $actor->id)->where('status', MembershipStatus::Active)->first();
            if (! $actorMembership || ! $actorMembership->is_owner) {
                throw ValidationException::withMessages([
                    'role' => 'Solo el propietario titular de la empresa puede modificar el rol de propietario.',
                ]);
            }
        }

        // Enforce privilege delegation boundary: actor cannot delegate permissions they do not possess
        $actorContext = $this->companyAuth->resolveContext($actor, $company);
        $actorPermissions = $actorContext ? $actorContext->effectivePermissionSlugs : [];
        $requestedPermissionSlugs = Permission::whereIn('id', $validPermissionIds)->pluck('slug')->all();
        $unauthorized = array_diff($requestedPermissionSlugs, $actorPermissions);

        if (! empty($unauthorized)) {
            throw ValidationException::withMessages([
                'permissions' => 'No tiene autorización para delegar permisos que no posee ('.implode(', ', $unauthorized).').',
            ]);
        }

        // Ownership transfer permission is reserved strictly to the owner
        if (in_array('commercial.ownership.transfer', $requestedPermissionSlugs, true)) {
            $actorMembership = $company->memberships()->where('user_id', $actor->id)->where('status', MembershipStatus::Active)->first();
            if (! $actorMembership || ! $actorMembership->is_owner) {
                throw ValidationException::withMessages([
                    'permissions' => 'El permiso de transferencia de titularidad está reservado exclusivamente para el propietario.',
                ]);
            }
        }

        return DB::connection('central')->transaction(function () use ($role, $name, $description, $validPermissionIds, $actor, $company) {
            $role->update([
                'name' => $name,
                'description' => $description,
            ]);

            $role->permissions()->sync($validPermissionIds);

            CentralAuditLog::create([
                'user_id' => $actor->id,
                'company_id' => $company->id,
                'action' => 'company.role.updated',
                'auditable_type' => $role->getMorphClass(),
                'auditable_id' => (string) $role->id,
                'new_values' => [
                    'name' => $name,
                    'permissions' => $validPermissionIds,
                ],
                'created_at' => now(),
            ]);

            return $role;
        });
    }

    /**
     * Delete a custom company role.
     */
    public function deleteRole(Company $company, CompanyRole $role, User $actor): void
    {
        $licenseResult = $this->licenseResolver->resolveEffectiveLicense($company);
        if ($licenseResult->validityStatus === LicenseValidityStatus::Expired) {
            throw ValidationException::withMessages([
                'license' => 'No es posible eliminar roles mientras la licencia de la empresa se encuentre vencida.',
            ]);
        }

        if ($role->company_id !== $company->id) {
            throw ValidationException::withMessages([
                'role' => 'El rol no pertenece a la empresa seleccionada.',
            ]);
        }

        if ($role->is_system || in_array($role->slug, self::RESERVED_SLUGS, true)) {
            throw ValidationException::withMessages([
                'role' => 'Los roles reservados del sistema no pueden ser eliminados.',
            ]);
        }

        $hasMembers = $role->memberships()->exists();
        if ($hasMembers) {
            throw ValidationException::withMessages([
                'role' => 'No se puede eliminar un rol asignado a miembros. Reasigne primero los roles de los miembros.',
            ]);
        }

        DB::connection('central')->transaction(function () use ($role, $actor, $company) {
            $role->permissions()->detach();
            $role->delete();

            CentralAuditLog::create([
                'user_id' => $actor->id,
                'company_id' => $company->id,
                'action' => 'company.role.deleted',
                'auditable_type' => $role->getMorphClass(),
                'auditable_id' => (string) $role->id,
                'new_values' => [
                    'slug' => $role->slug,
                    'name' => $role->name,
                ],
                'created_at' => now(),
            ]);
        });
    }
}
