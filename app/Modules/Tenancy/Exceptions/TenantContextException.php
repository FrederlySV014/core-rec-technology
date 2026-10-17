<?php

namespace App\Modules\Tenancy\Exceptions;

use Throwable;

class TenantContextException extends TenantException
{
    public function __construct(
        string $message = 'Operación comercial no permitida: no hay un contexto empresarial activo o se intentó alterar el contexto durante una operación en curso.',
        int $statusCode = 500,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
