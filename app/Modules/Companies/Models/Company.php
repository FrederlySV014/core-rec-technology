<?php

namespace App\Modules\Companies\Models;

use App\Modules\Access\Models\CompanyRole;
use App\Modules\Companies\Enums\CompanyStatus;
use App\Modules\Identity\Models\Membership;
use App\Modules\Licensing\Models\License;
use App\Modules\Provisioning\Enums\ProvisioningStatus;
use App\Modules\Provisioning\Models\CompanyDatabaseConnection;
use App\Modules\Provisioning\Models\ProvisioningLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $legal_name
 * @property string|null $trade_name
 * @property string $country_code
 * @property string $tax_id_type
 * @property string $tax_id
 * @property string $slug
 * @property string $contact_email
 * @property string|null $contact_phone
 * @property CompanyStatus $administrative_status
 * @property ProvisioningStatus $technical_provisioning_status
 */
class Company extends Model
{
    use SoftDeletes;

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
        'legal_name',
        'trade_name',
        'country_code',
        'tax_id_type',
        'tax_id',
        'slug',
        'contact_email',
        'contact_phone',
        'administrative_status',
        'technical_provisioning_status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'administrative_status' => CompanyStatus::class,
            'technical_provisioning_status' => ProvisioningStatus::class,
        ];
    }

    /**
     * Memberships associated with this company.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Licenses contracted by this company.
     *
     * @return HasMany<License, $this>
     */
    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    /**
     * Company-scoped roles.
     *
     * @return HasMany<CompanyRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(CompanyRole::class);
    }

    /**
     * Database connection configuration for the company's commercial database.
     *
     * @return HasOne<CompanyDatabaseConnection, $this>
     */
    public function databaseConnection(): HasOne
    {
        return $this->hasOne(CompanyDatabaseConnection::class);
    }

    /**
     * History of provisioning logs.
     *
     * @return HasMany<ProvisioningLog, $this>
     */
    public function provisioningLogs(): HasMany
    {
        return $this->hasMany(ProvisioningLog::class);
    }
}
