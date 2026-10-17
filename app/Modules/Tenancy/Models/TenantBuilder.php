<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Exceptions\TenantContextException;
use App\Modules\Tenancy\Services\TenantConnectionManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Custom Eloquent query builder for TenantModel instances.
 * Enforces that a query constructed in tenant context A cannot be executed in tenant context B.
 *
 * @template TModel of TenantModel
 *
 * @extends Builder<TModel>
 */
class TenantBuilder extends Builder
{
    /**
     * The company ID to which this query was bound when initialized.
     */
    protected ?int $boundCompanyId = null;

    /**
     * Create a new Eloquent query builder instance.
     */
    public function __construct(QueryBuilder $query)
    {
        parent::__construct($query);

        if (app()->bound(TenantConnectionManager::class) && app(TenantConnectionManager::class)->hasActiveTenant()) {
            $this->boundCompanyId = app(TenantConnectionManager::class)->getActiveCompany()?->id;
        }
    }

    /**
     * Assert that this query builder is executing within the same tenant context it was constructed in.
     *
     * @throws TenantContextException
     */
    public function assertMatchingTenantContext(): void
    {
        if (! app()->bound(TenantConnectionManager::class)) {
            return;
        }

        $manager = app(TenantConnectionManager::class);
        $manager->assertActiveTenantContext();

        $currentCompanyId = $manager->getActiveCompany()?->id;

        if ($this->boundCompanyId !== null && $currentCompanyId !== null && $this->boundCompanyId !== $currentCompanyId) {
            throw new TenantContextException(
                "Operación de consulta comercial rechazada: la consulta fue construida para la empresa [{$this->boundCompanyId}] pero el contexto activo actual pertenece a la empresa [{$currentCompanyId}]."
            );
        }
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param  array<int, string>|string  $columns
     * @return Collection<int, TModel>
     */
    public function get($columns = ['*'])
    {
        $this->assertMatchingTenantContext();

        return parent::get($columns);
    }

    /**
     * Update records in the database.
     *
     * @param  array<string, mixed>  $values
     * @return int
     */
    public function update(array $values)
    {
        $this->assertMatchingTenantContext();

        return parent::update($values);
    }

    /**
     * Delete records from the database.
     *
     * @return mixed
     */
    public function delete()
    {
        $this->assertMatchingTenantContext();

        return parent::delete();
    }
}
