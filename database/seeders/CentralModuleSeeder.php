<?php

namespace Database\Seeders;

use App\Modules\Licensing\Models\Module;
use Illuminate\Database\Seeder;

class CentralModuleSeeder extends Seeder
{
    /**
     * Run the database seeds idempotently.
     */
    public function run(): void
    {
        $modules = [
            [
                'slug' => 'core_commercial',
                'name' => 'Núcleo Comercial',
                'description' => 'Configuración de empresa, sedes, monedas y catálogos base.',
                'is_core' => true,
                'status' => 'active',
                'dependencies' => [],
            ],
            [
                'slug' => 'inventory',
                'name' => 'Gestión de Inventarios',
                'description' => 'Productos, variantes, existencias, almacenes y movimientos.',
                'is_core' => false,
                'status' => 'active',
                'dependencies' => ['core_commercial'],
            ],
            [
                'slug' => 'purchases',
                'name' => 'Compras y Proveedores',
                'description' => 'Órdenes de compra, recepción de mercadería y cuentas por pagar.',
                'is_core' => false,
                'status' => 'active',
                'dependencies' => ['inventory'],
            ],
            [
                'slug' => 'sales',
                'name' => 'Ventas y Pedidos',
                'description' => 'Cotizaciones, órdenes de venta, cajas y cuentas por cobrar.',
                'is_core' => false,
                'status' => 'active',
                'dependencies' => ['inventory'],
            ],
            [
                'slug' => 'sunat_billing',
                'name' => 'Facturación Electrónica SUNAT',
                'description' => 'Emisión UBL 2.1 de boletas, facturas, notas de crédito/débito y CDR.',
                'is_core' => false,
                'status' => 'active',
                'dependencies' => ['sales'],
            ],
            [
                'slug' => 'storefront_api',
                'name' => 'Integración Tienda Web (API)',
                'description' => 'API de sincronización para e-commerce externo (catálogo, stock, pedidos).',
                'is_core' => false,
                'status' => 'active',
                'dependencies' => ['sales'],
            ],
            [
                'slug' => 'reports',
                'name' => 'Reportes y Analítica',
                'description' => 'Reportes de ventas, inventario, rentabilidad y cierres contables.',
                'is_core' => false,
                'status' => 'active',
                'dependencies' => ['sales'],
            ],
        ];

        // 1. Create or update modules
        $createdModules = [];
        foreach ($modules as $data) {
            $createdModules[$data['slug']] = Module::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'is_core' => $data['is_core'],
                    'status' => $data['status'],
                ]
            );
        }

        // 2. Sync dependencies idempotently
        foreach ($modules as $data) {
            $module = $createdModules[$data['slug']];
            $dependencyIds = array_map(
                fn (string $depSlug): int => (int) $createdModules[$depSlug]->id,
                $data['dependencies']
            );
            $module->dependencies()->sync($dependencyIds);
        }
    }
}
