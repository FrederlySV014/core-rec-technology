<?php

namespace App\Modules\Access\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $user_id
 * @property int $platform_role_id
 * @property CarbonInterface|string|null $assigned_at
 * @property CarbonInterface|string|null $suspended_at
 */
class PlatformUserRole extends Pivot
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
    protected $table = 'platform_user_roles';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;
}
