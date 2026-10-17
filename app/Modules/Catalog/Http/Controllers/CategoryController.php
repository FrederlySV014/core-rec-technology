<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Requests\SetStatusRequest;
use App\Modules\Catalog\Http\Requests\StoreCategoryRequest;
use App\Modules\Catalog\Http\Requests\UpdateCategoryRequest;
use App\Modules\Catalog\Models\Category;
use App\Modules\Companies\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class CategoryController extends Controller
{
    /**
     * Store a newly created category.
     */
    public function store(StoreCategoryRequest $request, Company $company): JsonResponse|RedirectResponse
    {
        $category = Category::create($request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Categoría creada exitosamente.',
                'category' => $category,
            ], 201);
        }

        return back()->with('success', 'Categoría creada exitosamente.');
    }

    /**
     * Update an existing category.
     */
    public function update(UpdateCategoryRequest $request, Company $company, int|string $category): JsonResponse|RedirectResponse
    {
        /** @var Category $cat */
        $cat = Category::findOrFail((int) $category);
        $cat->update($request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Categoría actualizada exitosamente.',
                'category' => $cat->fresh(),
            ]);
        }

        return back()->with('success', 'Categoría actualizada exitosamente.');
    }

    /**
     * Set the active/inactive state of a category explicitly and idempotently.
     */
    public function setStatus(SetStatusRequest $request, Company $company, int|string $category): JsonResponse|RedirectResponse
    {
        /** @var Category $cat */
        $cat = Category::findOrFail((int) $category);
        $newStatus = $request->boolean('is_active');

        if ($cat->is_active !== $newStatus) {
            $cat->update(['is_active' => $newStatus]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => $newStatus ? 'Categoría activada exitosamente.' : 'Categoría desactivada exitosamente.',
                'category' => $cat->fresh(),
            ]);
        }

        return back()->with('success', $newStatus ? 'Categoría activada exitosamente.' : 'Categoría desactivada exitosamente.');
    }
}
