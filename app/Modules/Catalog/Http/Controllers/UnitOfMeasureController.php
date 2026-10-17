<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Requests\SetStatusRequest;
use App\Modules\Catalog\Http\Requests\StoreUnitOfMeasureRequest;
use App\Modules\Catalog\Http\Requests\UpdateUnitOfMeasureRequest;
use App\Modules\Catalog\Models\UnitOfMeasure;
use App\Modules\Companies\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class UnitOfMeasureController extends Controller
{
    /**
     * Store a newly created unit of measure.
     */
    public function store(StoreUnitOfMeasureRequest $request, Company $company): JsonResponse|RedirectResponse
    {
        $unit = UnitOfMeasure::create($request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Unidad de medida creada exitosamente.',
                'unit' => $unit,
            ], 201);
        }

        return back()->with('success', 'Unidad de medida creada exitosamente.');
    }

    /**
     * Update an existing unit of measure.
     */
    public function update(UpdateUnitOfMeasureRequest $request, Company $company, int|string $unit): JsonResponse|RedirectResponse
    {
        /** @var UnitOfMeasure $u */
        $u = UnitOfMeasure::findOrFail((int) $unit);
        $u->update($request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Unidad de medida actualizada exitosamente.',
                'unit' => $u->fresh(),
            ]);
        }

        return back()->with('success', 'Unidad de medida actualizada exitosamente.');
    }

    /**
     * Set the active/inactive state of a unit of measure explicitly and idempotently.
     */
    public function setStatus(SetStatusRequest $request, Company $company, int|string $unit): JsonResponse|RedirectResponse
    {
        /** @var UnitOfMeasure $u */
        $u = UnitOfMeasure::findOrFail((int) $unit);
        $newStatus = $request->boolean('is_active');

        if ($u->is_active !== $newStatus) {
            $u->update(['is_active' => $newStatus]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => $newStatus ? 'Unidad de medida activada exitosamente.' : 'Unidad de medida desactivada exitosamente.',
                'unit' => $u->fresh(),
            ]);
        }

        return back()->with('success', $newStatus ? 'Unidad de medida activada exitosamente.' : 'Unidad de medida desactivada exitosamente.');
    }
}
