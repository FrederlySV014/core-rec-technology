<?php

namespace Database\Seeders;

use App\Modules\Access\Enums\PermissionScope;
use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\RoleTemplate;
use Illuminate\Database\Seeder;

class CentralRoleTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds idempotently.
     */
    public function run(): void
    {
        $companyPermissions = Permission::where('scope', PermissionScope::Company)->get()->keyBy('slug');

        $templates = [
            [
                'slug' => 'company_owner',
                'name' => 'Propietario de Empresa',
                'description' => 'Control total sobre la empresa, miembros y módulos comerciales.',
                'permissions' => $companyPermissions->pluck('slug')->all(),
            ],
            [
                'slug' => 'company_admin',
                'name' => 'Administrador Comercial',
                'description' => 'Administración operativa comercial de la empresa.',
                'permissions' => [
                    'commercial.company.view',
                    'commercial.members.manage',
                    'inventory.products.view',
                    'inventory.products.manage',
                    'inventory.categories.manage',
                    'inventory.units.manage',
                    'inventory.stock.view',
                    'inventory.stock.adjust',
                    'purchases.orders.view',
                    'purchases.orders.manage',
                    'sales.orders.view',
                    'sales.orders.create',
                    'sales.orders.cancel',
                    'sales.customers.manage',
                    'billing.invoices.view',
                    'billing.invoices.issue',
                    'billing.invoices.cancel',
                    'reports.sales.view',
                    'reports.inventory.view',
                ],
            ],
            [
                'slug' => 'sales_cashier',
                'name' => 'Cajero / Vendedor',
                'description' => 'Emisión de ventas, cotizaciones y consulta de stock.',
                'permissions' => [
                    'commercial.company.view',
                    'inventory.products.view',
                    'inventory.stock.view',
                    'sales.orders.view',
                    'sales.orders.create',
                    'sales.customers.manage',
                    'billing.invoices.view',
                    'billing.invoices.issue',
                ],
            ],
            [
                'slug' => 'warehouse_keeper',
                'name' => 'Almacenero / Logística',
                'description' => 'Gestión de existencias, almacenes e ingresos de compras.',
                'permissions' => [
                    'commercial.company.view',
                    'inventory.products.view',
                    'inventory.stock.view',
                    'inventory.stock.adjust',
                    'purchases.orders.view',
                ],
            ],
        ];

        foreach ($templates as $tmpl) {
            $template = RoleTemplate::updateOrCreate(
                ['slug' => $tmpl['slug']],
                [
                    'name' => $tmpl['name'],
                    'description' => $tmpl['description'],
                ]
            );

            $permissionIds = [];
            foreach ($tmpl['permissions'] as $slug) {
                if (isset($companyPermissions[$slug])) {
                    $permissionIds[] = $companyPermissions[$slug]->id;
                }
            }

            $template->permissions()->sync($permissionIds);
        }
    }
}
