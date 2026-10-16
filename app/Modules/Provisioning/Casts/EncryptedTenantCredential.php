<?php

namespace App\Modules\Provisioning\Casts;

use App\Modules\Provisioning\Services\TenantCredentialEncrypter;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<?string, ?string>
 */
class EncryptedTenantCredential implements CastsAttributes
{
    /**
     * Cast the given value from database.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return app(TenantCredentialEncrypter::class)->decrypt((string) $value);
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return app(TenantCredentialEncrypter::class)->encrypt((string) $value);
    }
}
