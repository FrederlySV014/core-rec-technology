<?php

use App\Modules\Access\Enums\PermissionScope;
use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\RoleTemplate;
use App\Modules\Licensing\Models\Module;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $inventoryModule = Module::where('slug', 'inventory')->first();
        $moduleId = $inventoryModule?->id;

        $catManage = Permission::firstOrCreate(
            ['slug' => 'inventory.categories.manage'],
            [
                'scope' => PermissionScope::Company,
                'module_id' => $moduleId,
                'name' => 'Gestionar Categorías',
                'description' => 'Crear, editar y activar o desactivar categorías de productos.',
            ]
        );

        $unitManage = Permission::firstOrCreate(
            ['slug' => 'inventory.units.manage'],
            [
                'scope' => PermissionScope::Company,
                'module_id' => $moduleId,
                'name' => 'Gestionar Unidades de Medida',
                'description' => 'Crear, editar y activar o desactivar unidades de medida.',
            ]
        );

        // Assign explicitly to standard role templates without modifying custom company roles
        $adminTemplate = RoleTemplate::where('slug', 'company_admin')->first();
        if ($adminTemplate) {
            $adminTemplate->permissions()->syncWithoutDetaching([$catManage->id, $unitManage->id]);
        }

        $ownerTemplate = RoleTemplate::where('slug', 'company_owner')->first();
        if ($ownerTemplate) {
            $ownerTemplate->permissions()->syncWithoutDetaching([$catManage->id, $unitManage->id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Permission::whereIn('slug', [
            'inventory.categories.manage',
            'inventory.units.manage',
        ])->delete();
    }
};
