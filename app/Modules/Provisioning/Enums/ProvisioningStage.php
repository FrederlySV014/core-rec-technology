<?php

namespace App\Modules\Provisioning\Enums;

enum ProvisioningStage: string
{
    case CreateDatabase = 'create_database';
    case RunMigrations = 'run_migrations';
    case SeedData = 'seed_data';
    case HealthCheck = 'health_check';
}
