<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Services\CatalogNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->name)) {
            $this->merge(['name' => CatalogNormalizer::normalizeName($this->name)]);
        }
        if ($this->has('description') && is_string($this->description)) {
            $this->merge(['description' => CatalogNormalizer::normalizeDescription($this->description)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
