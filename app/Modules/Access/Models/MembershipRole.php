<?php

namespace App\Modules\Access\Models;

use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Models\Membership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipRole extends Model
{
    /**
     * The database connection that should be used by the model.
     *
     * @var string
     */
    protected $connection = 'central';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'membership_roles';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'membership_id',
        'company_role_id',
    ];

    /**
     * Company context for the assignment.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Target membership.
     *
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * Target company role.
     *
     * @return BelongsTo<CompanyRole, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(CompanyRole::class, 'company_role_id');
    }
}
