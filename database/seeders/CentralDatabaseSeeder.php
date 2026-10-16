<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class CentralDatabaseSeeder extends Seeder
{
    /**
     * Run the central database seeds in order.
     */
    public function run(): void
    {
        $this->call([
            CentralModuleSeeder::class,
            CentralPermissionSeeder::class,
            CentralPlatformRoleSeeder::class,
            CentralRoleTemplateSeeder::class,
        ]);
    }
}
