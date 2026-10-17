<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Support\Carbon;

/**
 * Technical identity entity living exclusively inside each commercial tenant database.
 * Used to verify cryptographic identity match during request connection establishment.
 *
 * @property int $id
 * @property int $company_id
 * @property string $tax_id
 * @property string $schema_version
 * @property Carbon $provisioned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TenantIdentity extends TenantModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'tenant_identity';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'tax_id',
        'schema_version',
        'provisioned_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'provisioned_at' => 'datetime',
        ];
    }
}
