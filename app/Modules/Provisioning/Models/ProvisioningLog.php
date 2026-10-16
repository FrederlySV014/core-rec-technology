<?php

namespace App\Modules\Provisioning\Models;

use App\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Provisioning\Enums\ProvisioningStage;
use App\Modules\Provisioning\Enums\ProvisioningStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property ProvisioningStage $stage
 * @property ProvisioningStatus $status
 * @property array<string, mixed>|null $details
 * @property string|null $error_message
 * @property int|null $executed_by_user_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class ProvisioningLog extends Model
{
    /**
     * The database connection that should be used by the model.
     *
     * @var string
     */
    protected $connection = 'central';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'stage',
        'status',
        'details',
        'error_message',
        'executed_by_user_id',
        'started_at',
        'finished_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => ProvisioningStage::class,
            'status' => ProvisioningStatus::class,
            'details' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Company whose provisioning was logged.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * User who initiated or executed the provisioning action.
     *
     * @return BelongsTo<User, $this>
     */
    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_user_id');
    }
}
