<?php

namespace App\Modules\Tenancy\Exceptions;

use Throwable;

class TenantConnectionFailedException extends TenantException
{
    public function __construct(
        string $message = 'No fue posible establecer conexión con la base de datos comercial. Por favor intente más tarde.',
        int $statusCode = 503,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
