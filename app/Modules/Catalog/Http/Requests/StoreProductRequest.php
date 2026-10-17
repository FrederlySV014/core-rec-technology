<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\UnitOfMeasure;
use App\Modules\Catalog\Services\CatalogNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
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
        return [
            'sku' => [
                'required',
                'string',
                'max:100',
                Rule::unique(Product::class, 'sku'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'barcode' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique(Product::class, 'barcode'),
            ],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists(Category::class, 'id')->where(fn ($q) => $q->where('is_active', true)),
            ],
            'unit_of_measure_id' => [
                'required',
                'integer',
                Rule::exists(UnitOfMeasure::class, 'id')->where(fn ($q) => $q->where('is_active', true)),
            ],
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
            'category_id.exists' => 'La categoría seleccionada no existe o se encuentra inactiva.',
            'unit_of_measure_id.exists' => 'La unidad de medida seleccionada no existe o se encuentra inactiva.',
        ];
    }
}
