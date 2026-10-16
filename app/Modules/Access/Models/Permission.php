<?php

namespace App\Modules\Access\Models;

use App\Modules\Access\Enums\PermissionScope;
use App\Modules\Licensing\Models\Module;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
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
        'scope',
        'module_id',
        'name',
        'slug',
        'description',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => PermissionScope::class,
        ];
    }

    /**
     * Commercial module this permission belongs to (if scoped to company).
     *
     * @return BelongsTo<Module, $this>
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    /**
     * Platform roles that have this permission.
     *
     * @return BelongsToMany<PlatformRole, $this>
     */
    public function platformRoles(): BelongsToMany
    {
        return $this->belongsToMany(PlatformRole::class, 'platform_role_permissions');
    }

    /**
     * Role templates that include this permission.
     *
     * @return BelongsToMany<RoleTemplate, $this>
     */
    public function roleTemplates(): BelongsToMany
    {
        return $this->belongsToMany(RoleTemplate::class, 'role_template_permissions');
    }

    /**
     * Company roles that include this permission.
     *
     * @return BelongsToMany<CompanyRole, $this>
     */
    public function companyRoles(): BelongsToMany
    {
        return $this->belongsToMany(CompanyRole::class, 'company_role_permissions');
    }
}
