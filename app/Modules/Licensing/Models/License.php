<?php

namespace App\Modules\Licensing\Models;

use App\Modules\Companies\Models\Company;
use App\Modules\Licensing\Enums\LicenseAdministrativeStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property string $license_key
 * @property string $plan_name
 * @property LicenseAdministrativeStatus $administrative_status
 * @property CarbonInterface $starts_at
 * @property CarbonInterface|null $expires_at
 * @property int $grace_period_days
 * @property CarbonInterface|null $effective_ends_at
 * @property int $max_users
 * @property int $max_branches
 * @property string|null $notes
 */
class License extends Model
{
    /**
     * The database connection that should be used by the model.
     *
     * @var string
     */
    protected $connection = 'central';

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saving(function (self $license): void {
            if ($license->effective_ends_at === null && $license->expires_at !== null) {
                $license->effective_ends_at = $license->expires_at->addDays((int) $license->grace_period_days);
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'license_key',
        'plan_name',
        'administrative_status',
        'starts_at',
        'expires_at',
        'grace_period_days',
        'effective_ends_at',
        'max_users',
        'max_branches',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'administrative_status' => LicenseAdministrativeStatus::class,
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'grace_period_days' => 'integer',
            'effective_ends_at' => 'datetime',
            'max_users' => 'integer',
            'max_branches' => 'integer',
        ];
    }

    /**
     * Company that holds this license.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Modules enabled in this license.
     *
     * @return BelongsToMany<Module, $this>
     */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'license_modules')
            ->withPivot('settings');
    }

    /**
     * Raw license module records.
     *
     * @return HasMany<LicenseModule, $this>
     */
    public function licenseModules(): HasMany
    {
        return $this->hasMany(LicenseModule::class);
    }

    /**
     * Calculate effective end date including grace period.
     * Returns null if license is indefinite/perpetual.
     */
    public function effectiveEndsAt(): ?CarbonInterface
    {
        if ($this->effective_ends_at !== null) {
            return $this->effective_ends_at;
        }

        if ($this->expires_at === null) {
            return null;
        }

        return $this->expires_at->addDays((int) $this->grace_period_days);
    }

    /**
     * Check if the license is indefinite (no expiration date).
     */
    public function isIndefinite(): bool
    {
        return $this->expires_at === null;
    }
}
