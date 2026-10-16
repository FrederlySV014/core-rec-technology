<?php

namespace App\Modules\Provisioning\Services;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use RuntimeException;

class TenantCredentialEncrypter
{
    protected ?Encrypter $encrypter = null;

    /**
     * Resolve the dedicated encrypter for tenant database credentials.
     */
    public function getEncrypter(): Encrypter
    {
        if ($this->encrypter !== null) {
            return $this->encrypter;
        }

        $key = config('tenancy.credential_encryption_key');

        if (empty($key)) {
            // In testing/dev fallback if key is not explicitly set, derive deterministically from app.key
            $appKey = config('app.key');
            if (empty($appKey)) {
                throw new RuntimeException('Neither TENANT_DB_ENCRYPTION_KEY nor APP_KEY is configured.');
            }

            if (Str::startsWith($appKey, 'base64:')) {
                $appKey = base64_decode(substr($appKey, 7));
            }

            $rawKey = hash('sha256', 'tenant-db-credentials-salt:'.$appKey, true);
            $this->encrypter = new Encrypter($rawKey, (string) config('tenancy.credential_cipher', 'aes-256-cbc'));

            return $this->encrypter;
        }

        if (Str::startsWith($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        $cipher = (string) config('tenancy.credential_cipher', 'aes-256-cbc');
        $this->encrypter = new Encrypter($key, $cipher);

        return $this->encrypter;
    }

    /**
     * Encrypt a plain-text credential.
     */
    public function encrypt(string $value): string
    {
        return $this->getEncrypter()->encryptString($value);
    }

    /**
     * Decrypt an encrypted credential payload.
     */
    public function decrypt(string $payload): string
    {
        return $this->getEncrypter()->decryptString($payload);
    }
}
