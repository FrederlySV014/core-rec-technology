<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code Internal administrative code (tax/fiscal equivalence will be defined later)
 * @property string $name
 * @property bool $allows_decimal
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UnitOfMeasure extends TenantModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'units_of_measure';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'allows_decimal',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allows_decimal' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Products using this unit of measure.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'unit_of_measure_id');
    }

    /**
     * Scope a query to only include active units of measure.
     *
     * @param  Builder<UnitOfMeasure>  $query
     * @return Builder<UnitOfMeasure>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
