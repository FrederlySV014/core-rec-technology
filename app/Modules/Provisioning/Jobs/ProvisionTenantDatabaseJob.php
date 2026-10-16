<?php

namespace App\Modules\Provisioning\Jobs;

use App\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Provisioning\Services\TenantProvisioningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionTenantDatabaseJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $companyId,
        public ?int $actorUserId = null,
        public bool $isRetry = false
    ) {
        $this->afterCommit = true;
    }

    /**
     * Execute the job.
     */
    public function handle(TenantProvisioningService $service): void
    {
        $company = Company::findOrFail($this->companyId);
        $actor = $this->actorUserId ? User::find($this->actorUserId) : null;

        $service->provision($company, $actor, $this->isRetry);
    }
}
