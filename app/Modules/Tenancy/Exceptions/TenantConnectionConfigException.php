<?php

namespace App\Modules\Tenancy\Exceptions;

use Throwable;

class TenantConnectionConfigException extends TenantException
{
    public function __construct(
        string $message = 'Configuración de conexión comercial no encontrada para la empresa.',
        int $statusCode = 503,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
