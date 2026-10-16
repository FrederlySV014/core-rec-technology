<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tenant Database Credential Encryption Key
    |--------------------------------------------------------------------------
    | Dedicated encryption key for tenant database credentials, separate from
    | general application keys.
    |
    */
    'credential_encryption_key' => env('TENANT_DB_ENCRYPTION_KEY'),
    'credential_cipher' => env('TENANT_DB_CIPHER', 'aes-256-cbc'),

    /*
    |--------------------------------------------------------------------------
    | Server Provisioning Administrative Credentials
    |--------------------------------------------------------------------------
    | Shared infrastructure credentials for creating tenant databases.
    | Kept strictly in protected server configuration, not per company record.
    |
    */
    'provisioner' => [
        'host' => env('TENANT_DB_PROVISIONER_HOST', env('DB_HOST', '127.0.0.1')),
        'port' => (int) env('TENANT_DB_PROVISIONER_PORT', env('DB_PORT', 5432)),
        'database' => env('TENANT_DB_PROVISIONER_DATABASE', env('DB_DATABASE', 'postgres')),
        'username' => env('TENANT_DB_PROVISIONER_USERNAME', env('DB_USERNAME', 'postgres')),
        'password' => env('TENANT_DB_PROVISIONER_PASSWORD', env('DB_PASSWORD', '')),
        'db_prefix' => env('TENANT_DB_NAME_PREFIX', 'rec_tenant_'),
        'user_prefix' => env('TENANT_DB_USER_PREFIX', 'rec_'),
    ],
];
