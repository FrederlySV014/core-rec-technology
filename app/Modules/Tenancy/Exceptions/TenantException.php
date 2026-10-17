<?php

namespace App\Modules\Tenancy\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TenantException extends Exception
{
    public function __construct(
        string $message = 'Error en el contexto o conexión de la base de datos comercial.',
        protected int $statusCode = 500,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Render the exception into an HTTP response.
     */
    public function render(Request $request): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'status' => 'error',
                'message' => $this->getMessage(),
            ], $this->statusCode);
        }

        abort($this->statusCode, $this->getMessage());
    }
}
