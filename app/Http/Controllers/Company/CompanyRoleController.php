<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Modules\Access\DTOs\CompanyContext;
use App\Modules\Access\Enums\PermissionScope;
use App\Modules\Access\Models\CompanyRole;
use App\Modules\Access\Models\Permission;
use App\Modules\Access\Services\CompanyRoleService;
use App\Modules\Companies\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyRoleController extends Controller
{
    public function __construct(
        protected CompanyRoleService $roleService
    ) {}

    /**
     * Display a listing of roles for the company and catalog of company permissions.
     */
    public function index(Request $request): Response|JsonResponse
    {
        /** @var CompanyContext $context */
        $context = $request->attributes->get('company_context');
        $company = $context->company;

        $roles = $this->roleService->listRoles($company)->map(fn (CompanyRole $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'slug' => $r->slug,
            'description' => $r->description,
            'is_system' => $r->is_system,
            'members_count' => $r->memberships->count(),
            'permissions' => $r->permissions->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
            ]),
        ]);

        $availablePermissions = Permission::where('scope', PermissionScope::Company)
            ->with('module')
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'description' => $p->description,
                'module_name' => $p->module->name ?? 'Núcleo Comercial',
            ]);

        if ($request->wantsJson()) {
            return response()->json([
                'roles' => $roles,
                'availablePermissions' => $availablePermissions,
            ]);
        }

        return Inertia::render('company/roles/index', [
            'company' => [
                'name' => $company->legal_name,
                'slug' => $company->slug,
            ],
            'roles' => $roles,
            'availablePermissions' => $availablePermissions,
        ]);
    }

    /**
     * Store a newly created custom role for the company.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        /** @var CompanyContext $context */
        $context = $request->attributes->get('company_context');
        $company = $context->company;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:50', 'alpha_dash'],
            'description' => ['nullable', 'string', 'max:255'],
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role = $this->roleService->createRole(
            company: $company,
            name: $validated['name'],
            slug: $validated['slug'],
            description: $validated['description'] ?? null,
            permissionIds: $validated['permission_ids'],
            actor: $request->user()
        );

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Rol personalizado creado exitosamente.',
                'role_id' => $role->id,
            ], 201);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Rol personalizado creado exitosamente.',
        ]);

        return back();
    }

    /**
     * Update an existing custom company role.
     */
    public function update(Request $request, Company $company, CompanyRole $role): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $this->roleService->updateRole(
            company: $company,
            role: $role,
            name: $validated['name'],
            description: $validated['description'] ?? null,
            permissionIds: $validated['permission_ids'],
            actor: $request->user()
        );

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Rol actualizado exitosamente.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Rol actualizado exitosamente.',
        ]);

        return back();
    }

    /**
     * Remove a custom company role.
     */
    public function destroy(Request $request, Company $company, CompanyRole $role): RedirectResponse|JsonResponse
    {
        $this->roleService->deleteRole($company, $role, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Rol eliminado exitosamente.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Rol eliminado exitosamente.',
        ]);

        return back();
    }
}
