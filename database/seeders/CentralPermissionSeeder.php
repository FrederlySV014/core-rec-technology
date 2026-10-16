<?php

namespace Database\Seeders;

use App\Modules\Access\Enums\PermissionScope;
use App\Modules\Access\Models\Permission;
use App\Modules\Licensing\Models\Module;
use Illuminate\Database\Seeder;

class CentralPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds idempotently.
     */
    public function run(): void
    {
        // 1. Platform Permissions (SaaS Administration)
        $platformPermissions = [
            [
                'slug' => 'platform.companies.view',
                'name' => 'Ver Empresas',
                'description' => 'Visualizar empresas registradas en la plataforma.',
            ],
            [
                'slug' => 'platform.companies.manage',
                'name' => 'Administrar Empresas',
                'description' => 'Crear, editar, suspender o cancelar empresas.',
            ],
            [
                'slug' => 'platform.licenses.view',
                'name' => 'Ver Licencias',
                'description' => 'Visualizar contratos y licencias de empresas.',
            ],
            [
                'slug' => 'platform.licenses.manage',
                'name' => 'Administrar Licencias',
                'description' => 'Emitir, renovar, suspender y modificar licencias y módulos habilitados.',
            ],
            [
                'slug' => 'platform.modules.manage',
                'name' => 'Administrar Catálogo de Módulos',
                'description' => 'Crear y actualizar la oferta de módulos del SaaS.',
            ],
            [
                'slug' => 'platform.provisioning.manage',
                'name' => 'Administrar Aprovisionamiento',
                'description' => 'Ejecutar aprovisionamiento, migraciones y mantenimiento de bases empresariales.',
            ],
            [
                'slug' => 'platform.audit.view',
                'name' => 'Ver Auditoría Central',
                'description' => 'Consultar bitácora de eventos centrales de la plataforma.',
            ],
            [
                'slug' => 'platform.staff.view',
                'name' => 'Ver Personal de Plataforma',
                'description' => 'Visualizar operadores y roles de plataforma.',
            ],
            [
                'slug' => 'platform.staff.manage',
                'name' => 'Administrar Personal de Plataforma',
                'description' => 'Incorporar operadores, asignar/retirar roles y suspender o revocar acceso de plataforma.',
            ],
        ];

        foreach ($platformPermissions as $perm) {
            Permission::updateOrCreate(
                ['slug' => $perm['slug']],
                [
                    'scope' => PermissionScope::Platform,
                    'module_id' => null,
                    'name' => $perm['name'],
                    'description' => $perm['description'],
                ]
            );
        }

        // 2. Company Permissions (Commercial Operations, mapped to modules)
        $moduleMap = Module::pluck('id', 'slug');

        $companyPermissions = [
            // core_commercial
            'core_commercial' => [
                ['slug' => 'commercial.company.view', 'name' => 'Ver Datos de Empresa', 'description' => 'Ver información general de la empresa.'],
                ['slug' => 'commercial.company.update', 'name' => 'Editar Datos de Empresa', 'description' => 'Modificar datos de la empresa, sedes y configuración general.'],
                ['slug' => 'commercial.members.manage', 'name' => 'Administrar Miembros y Roles', 'description' => 'Invitar usuarios a la empresa y asignar roles comerciales.'],
            ],
            // inventory
            'inventory' => [
                ['slug' => 'inventory.products.view', 'name' => 'Ver Catálogo de Productos', 'description' => 'Listar productos, variantes y precios.'],
                ['slug' => 'inventory.products.manage', 'name' => 'Administrar Productos', 'description' => 'Crear, editar y deshabilitar productos y categorías.'],
                ['slug' => 'inventory.stock.view', 'name' => 'Ver Stock', 'description' => 'Consultar existencias y almacenes.'],
                ['slug' => 'inventory.stock.adjust', 'name' => 'Ajustar Inventario', 'description' => 'Registrar entradas, salidas y transferencias de inventario.'],
            ],
            // purchases
            'purchases' => [
                ['slug' => 'purchases.orders.view', 'name' => 'Ver Compras', 'description' => 'Listar órdenes de compra y proveedores.'],
                ['slug' => 'purchases.orders.manage', 'name' => 'Administrar Compras', 'description' => 'Crear y aprobar compras y recepción de mercadería.'],
            ],
            // sales
            'sales' => [
                ['slug' => 'sales.orders.view', 'name' => 'Ver Ventas y Pedidos', 'description' => 'Listar ventas, cotizaciones y pedidos.'],
                ['slug' => 'sales.orders.create', 'name' => 'Crear Ventas y Pedidos', 'description' => 'Generar nuevas ventas y cotizaciones.'],
                ['slug' => 'sales.orders.cancel', 'name' => 'Anular Ventas', 'description' => 'Anular ventas y devoluciones.'],
                ['slug' => 'sales.customers.manage', 'name' => 'Administrar Clientes Comerciales', 'description' => 'Gestionar clientes y cuentas por cobrar.'],
            ],
            // sunat_billing
            'sunat_billing' => [
                ['slug' => 'billing.invoices.view', 'name' => 'Ver Comprobantes Electrónicos', 'description' => 'Consultar facturas, boletas y CDR emitidos.'],
                ['slug' => 'billing.invoices.issue', 'name' => 'Emitir Comprobantes SUNAT', 'description' => 'Firmar y enviar comprobantes UBL a SUNAT.'],
                ['slug' => 'billing.invoices.cancel', 'name' => 'Anular Comprobantes SUNAT', 'description' => 'Emitir comunicaciones de baja y notas de crédito electrónicas.'],
            ],
            // storefront_api
            'storefront_api' => [
                ['slug' => 'storefront.api.manage', 'name' => 'Administrar API Tienda Web', 'description' => 'Gestionar credenciales y sincronización de e-commerce.'],
            ],
            // reports
            'reports' => [
                ['slug' => 'reports.sales.view', 'name' => 'Ver Reportes de Ventas', 'description' => 'Generar reportes y analítica de ventas y cobranzas.'],
                ['slug' => 'reports.inventory.view', 'name' => 'Ver Reportes de Inventario', 'description' => 'Generar reportes de rotación, existencias y costos.'],
            ],
        ];

        foreach ($companyPermissions as $moduleSlug => $permissions) {
            $moduleId = $moduleMap[$moduleSlug] ?? null;

            foreach ($permissions as $perm) {
                Permission::updateOrCreate(
                    ['slug' => $perm['slug']],
                    [
                        'scope' => PermissionScope::Company,
                        'module_id' => $moduleId,
                        'name' => $perm['name'],
                        'description' => $perm['description'],
                    ]
                );
            }
        }
    }
}
