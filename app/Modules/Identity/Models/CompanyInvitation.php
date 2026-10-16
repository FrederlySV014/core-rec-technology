<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Modules\Companies\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $company_id
 * @property string $email
 * @property string $token_hash
 * @property list<int>|null $role_ids
 * @property int|null $invited_by_user_id
 * @property string $status
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $accepted_at
 * @property CarbonInterface|null $cancelled_at
 * @property-read Company $company
 * @property-read User|null $invitedBy
 */
class CompanyInvitation extends Model
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
        'email',
        'token_hash',
        'role_ids',
        'invited_by_user_id',
        'status',
        'expires_at',
        'accepted_at',
        'cancelled_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role_ids' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Company issuing the invitation.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * User who created the invitation.
     *
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * Determine if invitation is pending and not expired.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending' && ! $this->isExpired();
    }

    /**
     * Determine if invitation has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Create a plain text token and return [plainToken, tokenHash].
     *
     * @return array{0: string, 1: string}
     */
    public static function createTokenPair(): array
    {
        $plainToken = Str::random(40);
        $tokenHash = hash('sha256', $plainToken);

        return [$plainToken, $tokenHash];
    }
}
