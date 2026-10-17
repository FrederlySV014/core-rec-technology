<?php

namespace App\Modules\Catalog\Services;

class CatalogNormalizer
{
    /**
     * Normalize SKU: uppercase, trim, reject empty.
     */
    public static function normalizeSku(string $sku): string
    {
        return mb_strtoupper(trim($sku), 'UTF-8');
    }

    /**
     * Normalize Unit Code: uppercase, trim, reject empty.
     */
    public static function normalizeUnitCode(string $code): string
    {
        return mb_strtoupper(trim($code), 'UTF-8');
    }

    /**
     * Normalize Barcode: trim, nullify if empty.
     */
    public static function normalizeBarcode(?string $barcode): ?string
    {
        if ($barcode === null) {
            return null;
        }

        $trimmed = trim($barcode);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Normalize Name: trim.
     */
    public static function normalizeName(string $name): string
    {
        return trim($name);
    }

    /**
     * Normalize Description: trim, nullify if empty.
     */
    public static function normalizeDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $trimmed = trim($description);

        return $trimmed === '' ? null : $trimmed;
    }
}
