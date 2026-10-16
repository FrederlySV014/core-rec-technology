<?php

use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\PlatformRole;
use App\Modules\Access\Models\RoleTemplate;
use App\Modules\Licensing\Models\Module;
use Database\Seeders\CentralDatabaseSeeder;

describe('Central Seeders Idempotency', function () {
    test('seeders can be executed repeatedly without duplicates or errors', function () {
        // Run seeders first time
        $this->seed(CentralDatabaseSeeder::class);

        $moduleCountFirst = Module::count();
        $permCountFirst = Permission::count();
        $roleCountFirst = PlatformRole::count();
        $templateCountFirst = RoleTemplate::count();

        expect($moduleCountFirst)->toBeGreaterThan(0);
        expect($permCountFirst)->toBeGreaterThan(0);
        expect($roleCountFirst)->toBeGreaterThan(0);
        expect($templateCountFirst)->toBeGreaterThan(0);

        // Run seeders second time (idempotency check)
        $this->seed(CentralDatabaseSeeder::class);

        expect(Module::count())->toBe($moduleCountFirst);
        expect(Permission::count())->toBe($permCountFirst);
        expect(PlatformRole::count())->toBe($roleCountFirst);
        expect(RoleTemplate::count())->toBe($templateCountFirst);

        // Verify core module relationships
        $sunatModule = Module::where('slug', 'sunat_billing')->first();
        expect($sunatModule->dependencies->pluck('slug')->all())->toContain('sales');

        $superAdminRole = PlatformRole::where('slug', 'super_admin')->first();
        expect($superAdminRole->permissions)->not->toBeEmpty();
    });
});
