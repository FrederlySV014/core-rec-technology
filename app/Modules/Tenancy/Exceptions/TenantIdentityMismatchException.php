<?php

namespace App\Modules\Tenancy\Exceptions;

use Throwable;

class TenantIdentityMismatchException extends TenantException
{
    public function __construct(
        string $message = 'Inconsistencia crítica de identidad en la base de datos comercial asignada. La conexión ha sido revocada inmediatamente por seguridad.',
        int $statusCode = 500,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
