<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Modules\Access\Models\CompanyRole;
use App\Modules\Access\Models\MembershipRole;
use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Enums\MembershipStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property MembershipStatus $status
 * @property bool $is_owner
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $user
 * @property-read Company $company
 */
class Membership extends Model
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
        'user_id',
        'status',
        'is_owner',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'is_owner' => 'boolean',
        ];
    }

    /**
     * Company to which this membership belongs.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * User holding this membership.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Roles assigned to this membership.
     *
     * @return BelongsToMany<CompanyRole, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(CompanyRole::class, 'membership_roles', 'membership_id', 'company_role_id')
            ->withPivot('company_id')
            ->withTimestamps();
    }

    /**
     * Assign a company role to this membership enforcing tenant isolation.
     */
    public function assignRole(CompanyRole $role): void
    {
        $this->roles()->syncWithoutDetaching([
            $role->id => ['company_id' => $this->company_id],
        ]);
    }

    /**
     * Sync company roles to this membership enforcing tenant isolation.
     *
     * @param  array<int, int|CompanyRole>  $roles
     */
    public function syncRoles(array $roles): void
    {
        $syncData = [];
        foreach ($roles as $role) {
            $roleId = $role instanceof CompanyRole ? $role->id : $role;
            $syncData[$roleId] = ['company_id' => $this->company_id];
        }

        $this->roles()->sync($syncData);
    }

    /**
     * Raw membership roles pivot records.
     *
     * @return HasMany<MembershipRole, $this>
     */
    public function membershipRoles(): HasMany
    {
        return $this->hasMany(MembershipRole::class);
    }
}
