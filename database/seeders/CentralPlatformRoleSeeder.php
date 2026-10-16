<?php

namespace Database\Seeders;

use App\Modules\Access\Enums\PermissionScope;
use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\PlatformRole;
use Illuminate\Database\Seeder;

class CentralPlatformRoleSeeder extends Seeder
{
    /**
     * Run the database seeds idempotently.
     */
    public function run(): void
    {
        $platformPermissions = Permission::where('scope', PermissionScope::Platform)->get()->keyBy('slug');

        $roles = [
            [
                'slug' => 'super_admin',
                'name' => 'Superadministrador de Plataforma',
                'description' => 'Acceso integral a todas las funciones administrativas del SaaS.',
                'permissions' => $platformPermissions->pluck('slug')->all(),
            ],
            [
                'slug' => 'support_agent',
                'name' => 'Agente de Soporte Técnico',
                'description' => 'Soporte y diagnóstico de infraestructura y aprovisionamiento.',
                'permissions' => [
                    'platform.companies.view',
                    'platform.licenses.view',
                    'platform.provisioning.manage',
                    'platform.audit.view',
                ],
            ],
            [
                'slug' => 'sales_operator',
                'name' => 'Operador Comercial SaaS',
                'description' => 'Gestión de clientes y emisión de licencias.',
                'permissions' => [
                    'platform.companies.view',
                    'platform.companies.manage',
                    'platform.licenses.view',
                    'platform.licenses.manage',
                ],
            ],
        ];

        foreach ($roles as $roleData) {
            $role = PlatformRole::updateOrCreate(
                ['slug' => $roleData['slug']],
                [
                    'name' => $roleData['name'],
                    'description' => $roleData['description'],
                ]
            );

            $permissionIds = [];
            foreach ($roleData['permissions'] as $slug) {
                if (isset($platformPermissions[$slug])) {
                    $permissionIds[] = $platformPermissions[$slug]->id;
                }
            }

            $role->permissions()->sync($permissionIds);
        }
    }
}
