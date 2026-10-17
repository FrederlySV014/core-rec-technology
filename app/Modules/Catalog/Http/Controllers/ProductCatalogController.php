<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\DTOs\CompanyContext;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\UnitOfMeasure;
use App\Modules\Companies\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductCatalogController extends Controller
{
    /**
     * Display the catalog dashboard with products, categories, units of measure and filters.
     */
    public function index(Request $request, Company $company): Response|JsonResponse
    {
        /** @var CompanyContext|null $context */
        $context = $request->attributes->get('company_context');
        $effectiveCompany = $context instanceof CompanyContext ? $context->company : $company;

        $search = $request->string('search')->trim()->toString();
        $status = $request->string('status', 'all')->toString();
        $categoryId = $request->filled('category_id') ? (int) $request->input('category_id') : null;

        $productsQuery = Product::query()->with(['category', 'unitOfMeasure'])->orderBy('name');

        if ($search !== '') {
            $productsQuery->search($search);
        }

        if ($status === 'active') {
            $productsQuery->where('is_active', true);
        } elseif ($status === 'inactive') {
            $productsQuery->where('is_active', false);
        }

        if ($categoryId) {
            $productsQuery->where('category_id', $categoryId);
        }

        $products = $productsQuery->get()->map(fn (Product $p) => [
            'id' => $p->id,
            'sku' => $p->sku,
            'name' => $p->name,
            'description' => $p->description,
            'barcode' => $p->barcode,
            'category_id' => $p->category_id,
            'category' => $p->category ? [
                'id' => $p->category->id,
                'name' => $p->category->name,
                'is_active' => $p->category->is_active,
            ] : null,
            'unit_of_measure_id' => $p->unit_of_measure_id,
            'unit_of_measure' => [
                'id' => $p->unitOfMeasure->id,
                'code' => $p->unitOfMeasure->code,
                'name' => $p->unitOfMeasure->name,
                'allows_decimal' => $p->unitOfMeasure->allows_decimal,
                'is_active' => $p->unitOfMeasure->is_active,
            ],
            'is_active' => $p->is_active,
            'created_at' => $p->created_at?->toIso8601String(),
            'updated_at' => $p->updated_at?->toIso8601String(),
        ]);

        $categories = Category::withCount('products')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'is_active' => $c->is_active,
                'products_count' => $c->products_count,
            ]);

        $units = UnitOfMeasure::withCount('products')
            ->orderBy('code')
            ->get()
            ->map(fn (UnitOfMeasure $u) => [
                'id' => $u->id,
                'code' => $u->code,
                'name' => $u->name,
                'allows_decimal' => $u->allows_decimal,
                'is_active' => $u->is_active,
                'products_count' => $u->products_count,
            ]);

        $stats = [
            'total_products' => Product::count(),
            'active_products' => Product::where('is_active', true)->count(),
            'total_categories' => Category::count(),
            'active_categories' => Category::where('is_active', true)->count(),
            'total_units' => UnitOfMeasure::count(),
            'active_units' => UnitOfMeasure::where('is_active', true)->count(),
        ];

        $responseData = [
            'company' => [
                'legal_name' => $effectiveCompany->legal_name,
                'trade_name' => $effectiveCompany->trade_name,
                'slug' => $effectiveCompany->slug,
            ],
            'products' => $products,
            'categories' => $categories,
            'units' => $units,
            'stats' => $stats,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'category_id' => $categoryId,
            ],
            'can' => [
                'manage_products' => $context instanceof CompanyContext && $context->hasPermission('inventory.products.manage'),
                'manage_categories' => $context instanceof CompanyContext && $context->hasPermission('inventory.categories.manage'),
                'manage_units' => $context instanceof CompanyContext && $context->hasPermission('inventory.units.manage'),
            ],
            'is_read_only' => $context instanceof CompanyContext && $context->isReadOnly,
        ];

        if ($request->wantsJson()) {
            return response()->json($responseData);
        }

        return Inertia::render('company/catalog/index', $responseData);
    }
}
