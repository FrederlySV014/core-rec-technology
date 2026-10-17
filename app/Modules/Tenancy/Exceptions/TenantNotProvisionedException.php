<?php

namespace App\Modules\Tenancy\Exceptions;

use Throwable;

class TenantNotProvisionedException extends TenantException
{
    public function __construct(
        string $message = 'La base de datos comercial de la empresa no se encuentra disponible o aún está en proceso de aprovisionamiento.',
        int $statusCode = 503,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
