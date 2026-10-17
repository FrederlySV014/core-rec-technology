<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\UnitOfMeasure;
use App\Modules\Catalog\Services\CatalogNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('sku') && is_string($this->sku)) {
            $this->merge(['sku' => CatalogNormalizer::normalizeSku($this->sku)]);
        }
        if ($this->has('name') && is_string($this->name)) {
            $this->merge(['name' => CatalogNormalizer::normalizeName($this->name)]);
        }
        if ($this->has('description') && is_string($this->description)) {
            $this->merge(['description' => CatalogNormalizer::normalizeDescription($this->description)]);
        }
        if ($this->has('barcode')) {
            $this->merge(['barcode' => CatalogNormalizer::normalizeBarcode($this->barcode)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $productParam = $this->route('product');
        $currentProduct = null;
        if ($productParam instanceof Product) {
            $currentProduct = $productParam;
        } elseif (is_numeric($productParam)) {
            $found = Product::find((int) $productParam);
            if ($found instanceof Product) {
                $currentProduct = $found;
            }
        }

        // Category validation: allow retaining current inactive category, but reject assigning a different inactive category
        $inputCategoryId = $this->input('category_id');
        $categoryRule = ['nullable', 'integer'];
        if ($inputCategoryId !== null && $inputCategoryId !== '') {
            $isSameCategory = $currentProduct instanceof Product && (int) $currentProduct->category_id === (int) $inputCategoryId;
            if ($isSameCategory) {
                $categoryRule[] = Rule::exists(Category::class, 'id');
            } else {
                $categoryRule[] = Rule::exists(Category::class, 'id')->where(fn ($q) => $q->where('is_active', true));
            }
        }

        // Unit of measure validation: allow retaining current inactive unit, but reject assigning a different inactive unit
        $inputUnitId = $this->input('unit_of_measure_id');
        $unitRule = ['required', 'integer'];
        if ($inputUnitId !== null && $inputUnitId !== '') {
            $isSameUnit = $currentProduct instanceof Product && (int) $currentProduct->unit_of_measure_id === (int) $inputUnitId;
            if ($isSameUnit) {
                $unitRule[] = Rule::exists(UnitOfMeasure::class, 'id');
            } else {
                $unitRule[] = Rule::exists(UnitOfMeasure::class, 'id')->where(fn ($q) => $q->where('is_active', true));
            }
        }

        return [
            'sku' => [
                'required',
                'string',
                'max:100',
                Rule::unique(Product::class, 'sku')->ignore($currentProduct?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'barcode' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique(Product::class, 'barcode')->ignore($currentProduct?->id),
            ],
            'category_id' => $categoryRule,
            'unit_of_measure_id' => $unitRule,
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sku.unique' => 'El SKU ya se encuentra registrado para otro producto en esta empresa.',
            'barcode.unique' => 'El código de barras ya se encuentra registrado para otro producto en esta empresa.',
            'category_id.exists' => 'La categoría seleccionada no existe o se encuentra inactiva. No se permite asignar una referencia inactiva diferente.',
            'unit_of_measure_id.exists' => 'La unidad de medida seleccionada no existe o se encuentra inactiva. No se permite asignar una referencia inactiva diferente.',
        ];
    }
}
