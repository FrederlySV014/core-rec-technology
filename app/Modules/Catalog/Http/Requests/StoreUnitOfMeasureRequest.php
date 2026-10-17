<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\UnitOfMeasure;
use App\Modules\Catalog\Services\CatalogNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitOfMeasureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code') && is_string($this->code)) {
            $this->merge(['code' => CatalogNormalizer::normalizeUnitCode($this->code)]);
        }
        if ($this->has('name') && is_string($this->name)) {
            $this->merge(['name' => CatalogNormalizer::normalizeName($this->name)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique(UnitOfMeasure::class, 'code'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'allows_decimal' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'El código de unidad ya está en uso en esta empresa.',
        ];
    }
}
