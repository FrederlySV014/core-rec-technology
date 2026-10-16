<?php

namespace App\Modules\Provisioning\Exceptions;

use RuntimeException;

class ProvisioningException extends RuntimeException
{
    /**
     * Create a sanitized exception that removes passwords or sensitive connection strings.
     */
    public static function sanitized(string $message, ?\Throwable $previous = null): self
    {
        // Redact any password patterns (e.g., password=XYZ or IDENTIFIED BY 'XYZ')
        $sanitized = preg_replace('/password(=|\s*:\s*|\s+)(\'[^\']*\'|"[^"]*"|\S+)/i', 'password=***', $message) ?? $message;

        return new self($sanitized, 0, $previous);
    }
}
