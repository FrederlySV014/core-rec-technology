<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Exceptions\TenantContextException;
use App\Modules\Tenancy\Services\TenantConnectionManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;

/**
 * Base abstract model for all commercial tenant entities.
 * Enforces usage of the 'tenant' connection and verifies that an active, validated
 * tenant context is established before issuing any database operation.
 * Binds model instances and queries to the exact company context they were created under.
 */
abstract class TenantModel extends Model
{
    /**
     * The connection name for the model.
     *
     * @var string
     */
    protected $connection = 'tenant';

    /**
     * The company ID to which this model instance was bound at instantiation.
     */
    protected ?int $boundCompanyId = null;

    /**
     * Create a new Eloquent model instance.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        if (app()->bound(TenantConnectionManager::class) && app(TenantConnectionManager::class)->hasActiveTenant()) {
            $this->boundCompanyId = app(TenantConnectionManager::class)->getActiveCompany()?->id;
        }
    }

    /**
     * Create a new model instance that is existing.
     *
     * @param  array<string, mixed>  $attributes
     * @param  string|null  $connection
     * @return static
     */
    public function newFromBuilder($attributes = [], $connection = null)
    {
        $model = parent::newFromBuilder($attributes, $connection);

        if (app()->bound(TenantConnectionManager::class) && app(TenantConnectionManager::class)->hasActiveTenant()) {
            $model->boundCompanyId = app(TenantConnectionManager::class)->getActiveCompany()?->id;
        }

        return $model;
    }

    /**
     * Create a new Eloquent query builder for the model.
     *
     * @param  Builder  $query
     * @return TenantBuilder<TenantModel>
     */
    public function newEloquentBuilder($query): TenantBuilder
    {
        return new TenantBuilder($query);
    }

    /**
     * The "booted" method of the model.
     * Enforces that an active commercial tenant context exists before issuing queries.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('assert_tenant_context', function () {
            if (app()->bound(TenantConnectionManager::class)) {
                app(TenantConnectionManager::class)->assertActiveTenantContext();
            }
        });
    }

    /**
     * Save the model to the database, asserting that an active tenant context exists
     * and matches the company context under which this model was created/hydrated.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        $this->assertMatchingTenantContext();

        $saved = parent::save($options);

        if ($saved && app()->bound(TenantConnectionManager::class) && app(TenantConnectionManager::class)->hasActiveTenant()) {
            $this->boundCompanyId = app(TenantConnectionManager::class)->getActiveCompany()?->id;
        }

        return $saved;
    }

    /**
     * Delete the model from the database, asserting that an active tenant context exists
     * and matches the company context under which this model was created/hydrated.
     */
    public function delete(): ?bool
    {
        $this->assertMatchingTenantContext();

        return parent::delete();
    }

    /**
     * Assert that this model instance is operating under the exact tenant context it was instantiated for.
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
                "Operación sobre modelo comercial rechazada: el modelo fue instanciado para la empresa [{$this->boundCompanyId}] pero el contexto activo actual pertenece a la empresa [{$currentCompanyId}]."
            );
        }
    }
}
