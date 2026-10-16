<?php

use App\Modules\Access\Enums\PermissionScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The database connection that should be used by the migration.
     */
    protected $connection = 'central';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Partial Unique Index: At most ONE owner per company in PostgreSQL
        DB::connection($this->connection)->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS unique_active_company_owner ON memberships (company_id) WHERE is_owner = true;'
        );

        // 2. Company Ownership Transfers Table
        Schema::connection($this->connection)->create('company_ownership_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('current_owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 30)->default('pending'); // pending, accepted, cancelled, expired
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['target_user_id', 'status']);
        });

        // 3. Register Granular Permissions
        $coreCommercialModuleId = DB::connection($this->connection)
            ->table('modules')
            ->where('slug', 'core_commercial')
            ->value('id');

        $granularCompanyPermissions = [
            [
                'slug' => 'commercial.members.view',
                'name' => 'Ver Miembros de Empresa',
                'description' => 'Visualizar el listado de miembros, estados e invitaciones de la empresa.',
            ],
            [
                'slug' => 'commercial.members.invite',
                'name' => 'Invitar Miembros',
                'description' => 'Emitir invitaciones por correo electrónico para incorporar nuevos miembros.',
            ],
            [
                'slug' => 'commercial.members.assign_roles',
                'name' => 'Asignar Roles a Miembros',
                'description' => 'Asignar o remover roles a los miembros de la empresa dentro de la capacidad delegable.',
            ],
            [
                'slug' => 'commercial.members.suspend',
                'name' => 'Suspender Miembros',
                'description' => 'Suspender temporalmente el acceso de un miembro a la empresa.',
            ],
            [
                'slug' => 'commercial.members.reactivate',
                'name' => 'Reactivar Miembros',
                'description' => 'Reactivar el acceso de un miembro suspendido en la empresa.',
            ],
            [
                'slug' => 'commercial.members.revoke',
                'name' => 'Revocar Miembros',
                'description' => 'Revocar permanentemente la membresía de un usuario en la empresa.',
            ],
            [
                'slug' => 'commercial.roles.view',
                'name' => 'Ver Roles y Permisos',
                'description' => 'Consultar los roles definidos y sus permisos asignados en la empresa.',
            ],
            [
                'slug' => 'commercial.roles.create',
                'name' => 'Crear Roles Empresariales',
                'description' => 'Crear nuevos roles personalizados para la empresa.',
            ],
            [
                'slug' => 'commercial.roles.update',
                'name' => 'Editar Roles Empresariales',
                'description' => 'Modificar nombre y atribuciones de roles existentes.',
            ],
            [
                'slug' => 'commercial.roles.assign_permissions',
                'name' => 'Asignar Permisos a Roles',
                'description' => 'Configurar los permisos de un rol dentro de la capacidad de delegación del usuario.',
            ],
            [
                'slug' => 'commercial.roles.delete',
                'name' => 'Eliminar Roles Empresariales',
                'description' => 'Eliminar roles personalizados que no se encuentren asignados a miembros activos.',
            ],
            [
                'slug' => 'commercial.ownership.transfer',
                'name' => 'Transferir Titularidad de Empresa',
                'description' => 'Iniciar la transferencia formal de propiedad de la empresa hacia otro miembro activo y verificado.',
            ],
        ];

        foreach ($granularCompanyPermissions as $perm) {
            DB::connection($this->connection)->table('permissions')->updateOrInsert(
                ['slug' => $perm['slug']],
                [
                    'scope' => PermissionScope::Company->value,
                    'module_id' => $coreCommercialModuleId,
                    'name' => $perm['name'],
                    'description' => $perm['description'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // Granular Platform Provisioning Permissions
        $granularPlatformPermissions = [
            [
                'slug' => 'platform.provisioning.view',
                'name' => 'Ver Aprovisionamiento',
                'description' => 'Consultar estado técnico y diagnóstico de aprovisionamiento de infraestructura.',
            ],
            [
                'slug' => 'platform.provisioning.retry',
                'name' => 'Reintentar Aprovisionamiento',
                'description' => 'Reintentar tareas fallidas de aprovisionamiento de bases de datos.',
            ],
        ];

        foreach ($granularPlatformPermissions as $perm) {
            DB::connection($this->connection)->table('permissions')->updateOrInsert(
                ['slug' => $perm['slug']],
                [
                    'scope' => PermissionScope::Platform->value,
                    'module_id' => null,
                    'name' => $perm['name'],
                    'description' => $perm['description'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // 4. Map Existing Role Permissions Gracefully
        // Map old 'commercial.members.manage' into granular permissions without privilege escalation:
        $allGranularPermIds = DB::connection($this->connection)
            ->table('permissions')
            ->whereIn('slug', array_column($granularCompanyPermissions, 'slug'))
            ->pluck('id', 'slug');

        $adminPermSlugs = [
            'commercial.members.view',
            'commercial.members.invite',
            'commercial.members.assign_roles',
            'commercial.members.suspend',
            'commercial.members.reactivate',
            'commercial.members.revoke',
            'commercial.roles.view',
            'commercial.roles.create',
            'commercial.roles.update',
            'commercial.roles.assign_permissions',
            'commercial.roles.delete',
        ];

        $oldManagePermId = DB::connection($this->connection)
            ->table('permissions')
            ->where('slug', 'commercial.members.manage')
            ->value('id');

        if ($oldManagePermId) {
            // A. Update Role Templates
            $ownerTemplate = DB::connection($this->connection)->table('role_templates')->where('slug', 'company_owner')->first();
            if ($ownerTemplate) {
                foreach ($allGranularPermIds as $pId) {
                    DB::connection($this->connection)->table('role_template_permissions')->updateOrInsert([
                        'role_template_id' => $ownerTemplate->id,
                        'permission_id' => $pId,
                    ]);
                }
            }

            $adminTemplate = DB::connection($this->connection)->table('role_templates')->where('slug', 'company_admin')->first();
            if ($adminTemplate) {
                foreach ($adminPermSlugs as $slug) {
                    if (isset($allGranularPermIds[$slug])) {
                        DB::connection($this->connection)->table('role_template_permissions')->updateOrInsert([
                            'role_template_id' => $adminTemplate->id,
                            'permission_id' => $allGranularPermIds[$slug],
                        ]);
                    }
                }
            }

            // B. Update Existing Company Roles that have commercial.members.manage
            $rolesWithManage = DB::connection($this->connection)
                ->table('company_role_permissions')
                ->where('permission_id', $oldManagePermId)
                ->pluck('company_role_id');

            foreach ($rolesWithManage as $roleId) {
                $role = DB::connection($this->connection)->table('company_roles')->where('id', $roleId)->first();
                $isOwnerRole = $role && $role->slug === 'company_owner';

                $slugsToAssign = $isOwnerRole ? array_keys($allGranularPermIds->toArray()) : $adminPermSlugs;

                foreach ($slugsToAssign as $slug) {
                    if (isset($allGranularPermIds[$slug])) {
                        DB::connection($this->connection)->table('company_role_permissions')->updateOrInsert([
                            'company_role_id' => $roleId,
                            'permission_id' => $allGranularPermIds[$slug],
                        ]);
                    }
                }
            }
        }

        // C. Adjust Platform Roles:
        // Support agent: Remove platform.provisioning.manage, Add platform.provisioning.view (NO retry by default)
        $supportRole = DB::connection($this->connection)->table('platform_roles')->where('slug', 'support_agent')->first();
        $provisioningManageId = DB::connection($this->connection)->table('permissions')->where('slug', 'platform.provisioning.manage')->value('id');
        $provisioningViewId = DB::connection($this->connection)->table('permissions')->where('slug', 'platform.provisioning.view')->value('id');
        $provisioningRetryId = DB::connection($this->connection)->table('permissions')->where('slug', 'platform.provisioning.retry')->value('id');

        if ($supportRole && $provisioningManageId && $provisioningViewId) {
            DB::connection($this->connection)->table('platform_role_permissions')
                ->where('platform_role_id', $supportRole->id)
                ->where('permission_id', $provisioningManageId)
                ->delete();

            DB::connection($this->connection)->table('platform_role_permissions')->updateOrInsert([
                'platform_role_id' => $supportRole->id,
                'permission_id' => $provisioningViewId,
            ]);
        }

        // Superadmin: receives provisioning.view and provisioning.retry
        $superAdminRole = DB::connection($this->connection)->table('platform_roles')->where('slug', 'super_admin')->first();
        if ($superAdminRole) {
            if ($provisioningViewId) {
                DB::connection($this->connection)->table('platform_role_permissions')->updateOrInsert([
                    'platform_role_id' => $superAdminRole->id,
                    'permission_id' => $provisioningViewId,
                ]);
            }
            if ($provisioningRetryId) {
                DB::connection($this->connection)->table('platform_role_permissions')->updateOrInsert([
                    'platform_role_id' => $superAdminRole->id,
                    'permission_id' => $provisioningRetryId,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('company_ownership_transfers');
        DB::connection($this->connection)->statement('DROP INDEX IF EXISTS unique_active_company_owner;');

        $newSlugs = [
            'commercial.members.view',
            'commercial.members.invite',
            'commercial.members.assign_roles',
            'commercial.members.suspend',
            'commercial.members.reactivate',
            'commercial.members.revoke',
            'commercial.roles.view',
            'commercial.roles.create',
            'commercial.roles.update',
            'commercial.roles.assign_permissions',
            'commercial.roles.delete',
            'commercial.ownership.transfer',
            'platform.provisioning.view',
            'platform.provisioning.retry',
        ];

        DB::connection($this->connection)->table('permissions')->whereIn('slug', $newSlugs)->delete();
    }
};
