<?php

namespace App\Modules\Provisioning\Models;

use App\Modules\Companies\Models\Company;
use App\Modules\Provisioning\Casts\EncryptedTenantCredential;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyDatabaseConnection extends Model
{
    /**
     * The database connection that should be used by the model.
     *
     * @var string
     */
    protected $connection = 'central';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'driver',
        'host',
        'port',
        'database',
        'schema',
        'app_username',
        'app_password',
        'migration_username',
        'migration_password',
        'ssl_mode',
        'is_external_host',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'app_password',
        'migration_password',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'is_external_host' => 'boolean',
            'app_password' => EncryptedTenantCredential::class,
            'migration_password' => EncryptedTenantCredential::class,
        ];
    }

    /**
     * Company that owns this connection configuration.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
