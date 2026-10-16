<?php

namespace App\Modules\Access\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property PlatformUserRole $pivot
 */
class PlatformRole extends Model
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
        'name',
        'slug',
        'description',
    ];

    /**
     * Permissions granted to this platform role.
     *
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'platform_role_permissions');
    }

    /**
     * Platform operators assigned this role.
     *
     * @return BelongsToMany<User, $this, PlatformUserRole>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'platform_user_roles')
            ->using(PlatformUserRole::class)
            ->withPivot(['assigned_at', 'suspended_at']);
    }
}
