<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Requests\SetStatusRequest;
use App\Modules\Catalog\Http\Requests\StoreProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Companies\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class ProductController extends Controller
{
    /**
     * Store a newly created simple product.
     */
    public function store(StoreProductRequest $request, Company $company): JsonResponse|RedirectResponse
    {
        $product = Product::create($request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Producto registrado exitosamente.',
                'product' => $product->load(['category', 'unitOfMeasure']),
            ], 201);
        }

        return back()->with('success', 'Producto registrado exitosamente.');
    }

    /**
     * Update an existing product.
     */
    public function update(UpdateProductRequest $request, Company $company, int|string $product): JsonResponse|RedirectResponse
    {
        /** @var Product $prod */
        $prod = Product::findOrFail((int) $product);
        $prod->update($request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Producto actualizado exitosamente.',
                'product' => $prod->fresh()->load(['category', 'unitOfMeasure']),
            ]);
        }

        return back()->with('success', 'Producto actualizado exitosamente.');
    }

    /**
     * Set the active/inactive state of a product explicitly and idempotently.
     */
    public function setStatus(SetStatusRequest $request, Company $company, int|string $product): JsonResponse|RedirectResponse
    {
        /** @var Product $prod */
        $prod = Product::findOrFail((int) $product);
        $newStatus = $request->boolean('is_active');

        if ($prod->is_active !== $newStatus) {
            $prod->update(['is_active' => $newStatus]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => $newStatus ? 'Producto activado exitosamente.' : 'Producto desactivado exitosamente.',
                'product' => $prod->fresh()->load(['category', 'unitOfMeasure']),
            ]);
        }

        return back()->with('success', $newStatus ? 'Producto activado exitosamente.' : 'Producto desactivado exitosamente.');
    }
}
