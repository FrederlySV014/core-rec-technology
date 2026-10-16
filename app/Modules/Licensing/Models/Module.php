<?php

namespace App\Modules\Licensing\Models;

use App\Modules\Access\Models\Permission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Module extends Model
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
        'is_core',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_core' => 'boolean',
        ];
    }

    /**
     * Modules this module directly depends on.
     *
     * @return BelongsToMany<Module, $this>
     */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(
            Module::class,
            'module_dependencies',
            'module_id',
            'depends_on_module_id'
        );
    }

    /**
     * Modules that depend on this module.
     *
     * @return BelongsToMany<Module, $this>
     */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(
            Module::class,
            'module_dependencies',
            'depends_on_module_id',
            'module_id'
        );
    }

    /**
     * Permissions belonging to this commercial module.
     *
     * @return HasMany<Permission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class);
    }

    /**
     * Licenses that include this module.
     *
     * @return BelongsToMany<License, $this>
     */
    public function licenses(): BelongsToMany
    {
        return $this->belongsToMany(License::class, 'license_modules')
            ->withPivot('settings');
    }
}
